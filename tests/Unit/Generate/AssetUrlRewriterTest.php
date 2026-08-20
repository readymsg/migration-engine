<?php

declare(strict_types=1);

namespace Tests\Unit\Generate;

use App\Data\AssemblyFailure;
use App\Data\AssemblyResult;
use App\Data\AssemblyStatus;
use App\Data\AssetRef;
use App\Data\BlockFillResult;
use App\Data\Brand;
use App\Data\ContentExtractionFailure;
use App\Data\ContentRef;
use App\Data\GlobalStyleBrief;
use App\Data\Manifest;
use App\Data\NavItem;
use App\Data\NavNode;
use App\Data\PuckOutput;
use App\Data\ScrubKind;
use App\Data\SiteStructure;
use App\Services\Generate\Assembler;
use App\Services\Generate\AssetUrlRewriter;
use App\Services\Generate\BlockCoercer;
use App\Services\Schema\DefaultPuckComponentSchema;
use JsonException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

// Post-assembly SE-CDN URL rewrite tests.
//
// AssetUrlRewriter closes the "live SE dependency" gap in the emitted
// Puck. Two invariants:
//   1. A SE-CDN URL WITH a matching AssetRef.source_url is rewritten
//      to that AssetRef.s3_key. An informational ScrubKind::
//      AssetUrlRewritten is recorded (audit — nothing invisible).
//   2. A SE-CDN URL WITHOUT a match stays live AND records a visible
//      ScrubKind::AssetRehostMissing. Live SE dependency MUST NEVER
//      be silent — the invariant this rewriter exists to enforce.
final class AssetUrlRewriterTest extends TestCase
{
    private function rewriter(): AssetUrlRewriter
    {
        return new AssetUrlRewriter;
    }

    private function emptyAssembly(PuckOutput $page): AssemblyResult
    {
        return new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, [$page]),
            failures: new DataCollection(AssemblyFailure::class, []),
            block_issues_by_slug: [],
            status: AssemblyStatus::Complete,
            style_brief: new GlobalStyleBrief(
                brand_voice: '',
                palette: [],
                layout_conventions: [],
                nav: new DataCollection(NavItem::class, []),
            ),
        );
    }

    /** @param array<int, AssetRef> $refs */
    private function manifest(array $refs = []): Manifest
    {
        return new Manifest(
            source_url: 'https://www.example.org/',
            org_id: 'ngin-1',
            structure: new SiteStructure(
                nav: new DataCollection(NavNode::class, []),
                pages_total: 0,
            ),
            provisioning: null,
            brand: new Brand(logo_source: 'flag'),
            content_refs: new DataCollection(ContentRef::class, []),
            asset_refs: new DataCollection(AssetRef::class, $refs),
            confidence: 1.0,
            content_failures: new DataCollection(ContentExtractionFailure::class, []),
        );
    }

    #[Test]
    public function whole_string_se_cdn_url_is_rewritten_to_s3_key(): void
    {
        $srcUrl = 'https://cdn4.sportngin.com/attachments/photo/64f2-211650277/banner.jpg';
        $s3 = 's3://fake/ngin-1/content_assets/deadbeef.jpg';
        $manifest = $this->manifest([
            new AssetRef(s3_key: $s3, mime_type: 'image/jpeg', source_url: $srcUrl),
        ]);
        $page = new PuckOutput(
            page_slug: 'home',
            content: [
                ['type' => 'Hero', 'props' => ['heading' => 'Welcome', 'background_image' => $srcUrl]],
            ],
            root: ['title' => 'Home'],
        );
        $out = $this->rewriter()->run($this->emptyAssembly($page), $manifest);
        $updated = $out->pages->items()[0];
        $this->assertSame($s3, $updated->content[0]['props']['background_image']);

        // Informational scrub entry recorded.
        $issues = $out->scrub_issues_by_slug['home'] ?? [];
        $this->assertCount(1, $issues);
        $this->assertSame(ScrubKind::AssetUrlRewritten, $issues[0]->kind);
        $this->assertStringContainsString($srcUrl, $issues[0]->dropped_content_summary);
        $this->assertStringContainsString($s3, $issues[0]->dropped_content_summary);
    }

    #[Test]
    public function unmatched_se_cdn_url_stays_live_and_records_a_visible_scrub(): void
    {
        $srcUrl = 'https://cdn3.sportngin.com/attachments/photo/111/orphan.png';
        // Manifest has NO matching AssetRef for this URL.
        $manifest = $this->manifest([]);
        $page = new PuckOutput(
            page_slug: 'home',
            content: [
                ['type' => 'Image', 'props' => ['src' => $srcUrl, 'alt' => 'x']],
            ],
            root: ['title' => 'Home'],
        );
        $out = $this->rewriter()->run($this->emptyAssembly($page), $manifest);
        $updated = $out->pages->items()[0];
        $this->assertSame($srcUrl, $updated->content[0]['props']['src'], 'URL must stay live when no AssetRef matches');
        $issues = $out->scrub_issues_by_slug['home'] ?? [];
        $this->assertCount(1, $issues);
        $this->assertSame(ScrubKind::AssetRehostMissing, $issues[0]->kind);
        $this->assertSame($srcUrl, $issues[0]->dropped_content_summary);
    }

    #[Test]
    public function normalisation_matches_url_with_query_string_or_fragment(): void
    {
        $srcUrl = 'https://cdn4.sportngin.com/attachments/photo/64f2/banner.jpg';
        $s3 = 's3://fake/ngin-1/content_assets/aa.jpg';
        $manifest = $this->manifest([
            new AssetRef(s3_key: $s3, mime_type: 'image/jpeg', source_url: $srcUrl),
        ]);
        // Emitted URL has query string + fragment — must still match.
        $emitted = $srcUrl.'?v=42#fragment';
        $page = new PuckOutput(
            page_slug: 'home',
            content: [
                ['type' => 'Hero', 'props' => ['heading' => 'X', 'background_image' => $emitted]],
            ],
            root: ['title' => 'Home'],
        );
        $out = $this->rewriter()->run($this->emptyAssembly($page), $manifest);
        $this->assertSame($s3, $out->pages->items()[0]->content[0]['props']['background_image']);
    }

    #[Test]
    public function nested_props_arrays_and_slot_content_are_walked(): void
    {
        $urlA = 'https://cdn1.sportngin.com/attachments/photo/a.jpg';
        $urlB = 'https://cdn2.sportngin.com/attachments/photo/b.jpg';
        $manifest = $this->manifest([
            new AssetRef(s3_key: 's3://fake/A.jpg', mime_type: 'image/jpeg', source_url: $urlA),
            new AssetRef(s3_key: 's3://fake/B.jpg', mime_type: 'image/jpeg', source_url: $urlB),
        ]);
        $page = new PuckOutput(
            page_slug: 'home',
            content: [
                [
                    'type' => 'Columns',
                    'props' => [
                        'columns' => [
                            ['children' => [
                                ['type' => 'Image', 'props' => ['src' => $urlA, 'alt' => 'a']],
                            ]],
                            ['children' => [
                                ['type' => 'Image', 'props' => ['src' => $urlB, 'alt' => 'b']],
                            ]],
                        ],
                    ],
                ],
            ],
            root: ['title' => 'Home'],
        );
        $out = $this->rewriter()->run($this->emptyAssembly($page), $manifest);
        $block = $out->pages->items()[0]->content[0];
        $this->assertSame('s3://fake/A.jpg', $block['props']['columns'][0]['children'][0]['props']['src']);
        $this->assertSame('s3://fake/B.jpg', $block['props']['columns'][1]['children'][0]['props']['src']);

        // Two rewrite events recorded.
        $issues = $out->scrub_issues_by_slug['home'] ?? [];
        $this->assertCount(2, $issues);
    }

    #[Test]
    public function non_se_cdn_urls_are_left_untouched_and_produce_no_scrub(): void
    {
        $orgUrl = 'https://www.example.org/hero.jpg';
        $manifest = $this->manifest([]);
        $page = new PuckOutput(
            page_slug: 'home',
            content: [
                ['type' => 'Hero', 'props' => ['heading' => 'x', 'background_image' => $orgUrl]],
                ['type' => 'Text', 'props' => ['body' => 'A sentence about our club.']],
            ],
            root: ['title' => 'Home'],
        );
        $out = $this->rewriter()->run($this->emptyAssembly($page), $manifest);
        $this->assertSame($orgUrl, $out->pages->items()[0]->content[0]['props']['background_image']);
        $this->assertSame([], $out->scrub_issues_by_slug);
    }

    #[Test]
    public function embedded_url_inside_text_body_is_rewritten_and_recorded(): void
    {
        $srcUrl = 'https://cdn2.sportngin.com/attachments/photo/inline.jpg';
        $s3 = 's3://fake/ngin-1/content_assets/inline.jpg';
        $manifest = $this->manifest([
            new AssetRef(s3_key: $s3, mime_type: 'image/jpeg', source_url: $srcUrl),
        ]);
        $body = "Our banner is at ![banner]({$srcUrl}) and appears on Home.";
        $page = new PuckOutput(
            page_slug: 'home',
            content: [['type' => 'Text', 'props' => ['body' => $body]]],
            root: ['title' => 'Home'],
        );
        $out = $this->rewriter()->run($this->emptyAssembly($page), $manifest);
        $newBody = $out->pages->items()[0]->content[0]['props']['body'];
        $this->assertStringContainsString($s3, $newBody, 'embedded URL must be rewritten in place');
        $this->assertStringNotContainsString($srcUrl, $newBody);

        $issues = $out->scrub_issues_by_slug['home'] ?? [];
        $this->assertCount(1, $issues);
        $this->assertSame(ScrubKind::AssetUrlRewritten, $issues[0]->kind);
    }

    #[Test]
    public function tbirdhoops_hero_background_image_is_rewritten_via_synthetic_manifest(): void
    {
        // Fixture-based check on the tbirdhoops Home Hero: the real
        // block-fill output has a live cdn4.sportngin.com/attachments/
        // photo/... URL. With a hand-built Manifest carrying an
        // AssetRef for that URL, the rewriter must swap it.
        $bf = $this->loadBlockFillFixture(base_path('tests/Fixtures/blockfill/tbirdhoops.json'));
        $schema = new DefaultPuckComponentSchema;
        $assembly = (new Assembler(new BlockCoercer($schema)))->run($bf);

        $home = null;
        foreach ($assembly->pages->items() as $p) {
            if ($p->page_slug === 'page-7188115') {
                $home = $p;
                break;
            }
        }
        $this->assertNotNull($home);
        $heroUrl = $home->content[0]['props']['background_image'] ?? null;
        $this->assertIsString($heroUrl);
        $this->assertStringContainsString('cdn', $heroUrl);
        $this->assertStringContainsString('sportngin.com', $heroUrl);

        $manifest = $this->manifest([
            new AssetRef(
                s3_key: 's3://fake/ngin-63620/content_assets/hero-rehosted.jpg',
                mime_type: 'image/jpeg',
                source_url: $heroUrl,
            ),
        ]);
        $out = (new AssetUrlRewriter)->run($assembly, $manifest);
        $updatedHome = null;
        foreach ($out->pages->items() as $p) {
            if ($p->page_slug === 'page-7188115') {
                $updatedHome = $p;
                break;
            }
        }
        $this->assertNotNull($updatedHome);
        $this->assertSame(
            's3://fake/ngin-63620/content_assets/hero-rehosted.jpg',
            $updatedHome->content[0]['props']['background_image'],
            'Home Hero background_image must be rewritten to the s3_key when Manifest.asset_refs matches'
        );
    }

    private function loadBlockFillFixture(string $path): BlockFillResult
    {
        if (! is_file($path)) {
            throw new RuntimeException("fixture missing: {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("read failed: {$path}");
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("invalid json: {$e->getMessage()}");
        }

        return BlockFillResult::from($decoded);
    }
}
