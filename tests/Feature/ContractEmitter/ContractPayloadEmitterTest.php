<?php

declare(strict_types=1);

namespace Tests\Feature\ContractEmitter;

use App\Data\AssetRef;
use App\Data\Brand;
use App\Data\ConversionFailure;
use App\Data\ConversionResult;
use App\Data\ConversionStatus;
use App\Data\GlobalStyleBrief;
use App\Data\NavItem;
use App\Data\OrgType;
use App\Data\ResolvedNavItem;
use App\Data\ResolvedNavStatus;
use App\Services\ContractEmitter\ContractPayloadEmitter;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

// End-to-end composition test for the ContractPayloadEmitter.
// Every collaborator is resolved from the container so this also
// exercises the AppServiceProvider bindings.
//
// Pins Contract Part VI self-check rules 7-11 (envelope-level):
//   - Every block has unique props.id within page.
//   - Exactly one page has slug="", all slugs CI-unique, no `view`.
//   - Every tl-asset:<ref> has an assets[] entry.
//   - data.root and data.zones empty per page.
//   - parentId dangling refused.
final class ContractPayloadEmitterTest extends TestCase
{
    private ContractPayloadEmitter $emitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emitter = app(ContractPayloadEmitter::class);
    }

    #[Test]
    public function synthetic_two_page_site_emits_valid_envelope(): void
    {
        // Two pages, mixed old-schema content, tokens registered.
        // Should produce a validation-clean envelope with zero errors.
        $result = new ConversionResult(
            conversion_id: 'test',
            org_id: 'test-org',
            source_url: 'https://www.tbirdhoops.org/',
            page_map: [
                'page-home' => ['content' => [
                    ['type' => 'Hero', 'props' => ['background_image' => 's3://x/hero.jpg', 'heading' => 'Welcome']],
                    ['type' => 'Text', 'props' => ['body' => '<p>Body copy</p>']],
                ], 'root' => [], 'zones' => []],
                'page-about' => ['content' => [
                    ['type' => 'Heading', 'props' => ['level' => 2, 'text' => 'About']],
                    ['type' => 'Text', 'props' => ['body' => '<p>Our story.</p>']],
                ], 'root' => [], 'zones' => []],
            ],
            nav: new DataCollection(ResolvedNavItem::class, [
                new ResolvedNavItem('Home', 'page-home', 0, ResolvedNavStatus::Resolved),
                new ResolvedNavItem('About', 'page-about', 1, ResolvedNavStatus::Resolved),
            ]),
            failures: new DataCollection(ConversionFailure::class, []),
            block_issues_by_slug: [],
            status: ConversionStatus::Completed,
            brand: new Brand(
                logo_source: 'header',
                logo_asset_ref: 's3://x/logo.png',
                logo_source_url: 'https://cdn.example.com/logo.png',
                palette: ['primary' => '#AE292E', 'text' => '#5C5151'],
            ),
            style_brief: new GlobalStyleBrief(brand_voice: '', palette: [], layout_conventions: [], nav: new DataCollection(NavItem::class, [])),
            asset_refs: new DataCollection(AssetRef::class, [
                new AssetRef(s3_key: 's3://x/hero.jpg', mime_type: 'image/jpeg', source_url: 'https://cdn.example.com/hero.jpg'),
                new AssetRef(s3_key: 's3://x/logo.png', mime_type: 'image/png', source_url: 'https://cdn.example.com/logo.png'),
            ]),
        );

        $out = $this->emitter->emit($result, OrgType::Club);

        // Zero validation errors.
        $errorCodes = array_map(fn ($e) => $e->code, $out->errors);
        $this->assertSame([], $errorCodes, 'valid input must produce zero validation errors; got: '.json_encode($errorCodes));
        $this->assertTrue($out->isValid());

        // Envelope shape.
        $env = $out->envelope;
        $this->assertSame(1, $env->schemaVersion);
        $this->assertSame('https://www.tbirdhoops.org/', $env->source->url);
        $this->assertSame(2, $env->source->pagesDiscovered);
        $this->assertSame(2, $env->source->pagesMapped);

        // Homepage rule.
        $homePages = array_filter($env->pages->items(), fn ($p) => $p->slug === '');
        $this->assertCount(1, $homePages);
        $home = array_values($homePages)[0];
        $this->assertSame('home', $home->id);
        $this->assertSame('Home', $home->title);

        // Measured palette carried through.
        $this->assertSame('#AE292E', $env->site->primaryColor);
        $this->assertSame('#5C5151', $env->site->neutralColor);

        // Assets: hero + logo (dedupe should mean only 2 total).
        $this->assertCount(2, $env->assets);

        // No S3 keys anywhere in the payload.
        $encoded = json_encode($env->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('s3://', $encoded, 'no S3 keys may reach the payload');

        // Every block has a unique id within its page.
        foreach ($env->pages as $page) {
            $ids = [];
            foreach ($page->data->content as $block) {
                $ids[] = $block->props['id'];
            }
            $this->assertSame(count($ids), count(array_unique($ids)), "block ids on page `{$page->slug}` must be unique");
        }
    }

    #[Test]
    public function tbirdhoops_fixture_emits_a_contract_valid_payload(): void
    {
        // The M1 milestone gate: replay the actual tbirdhoops fixture
        // (produced by engine:emit-preview-fixture, committed to
        // storage/app/public/preview/tbirdhoops.json) and assert the
        // emitter produces a validation-clean contract payload.
        $fixture = storage_path('app/public/preview/tbirdhoops.json');
        if (! file_exists($fixture)) {
            $this->markTestSkipped('tbirdhoops preview fixture missing; run engine:emit-preview-fixture');
        }
        $raw = json_decode((string) file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($raw);
        $result = ConversionResult::from($raw);

        $out = $this->emitter->emit($result, OrgType::Club);

        // Report violations verbatim so a failed run shows exactly
        // what's wrong.
        $errorDump = array_map(fn ($e) => ['code' => $e->code, 'message' => $e->message, 'path' => $e->path], $out->errors);
        $this->assertTrue(
            $out->isValid(),
            'tbirdhoops payload must be contract-valid; errors: '.json_encode($errorDump, JSON_PRETTY_PRINT),
        );

        // No S3 keys leaked into the payload.
        $encoded = json_encode($out->envelope->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('s3://', $encoded);
        $this->assertStringNotContainsString('/preview-assets', $encoded);

        // At least one page with slug="".
        $homeCount = 0;
        foreach ($out->envelope->pages as $p) {
            if ($p->slug === '') {
                $homeCount++;
            }
        }
        $this->assertSame(1, $homeCount);

        // The measured palette should be present.
        $this->assertSame('#AE292E', $out->envelope->site->primaryColor);
        $this->assertSame('#5C5151', $out->envelope->site->neutralColor);

        // Every asset in assets[] should be referenced (no orphans).
        // Orphans surface as WARNINGS not errors, so this is a soft
        // check: fail loudly here to surface any drift.
        $warningCodes = array_map(fn ($w) => $w->code, $out->warnings);
        $this->assertNotContains(
            'orphaned_asset',
            $warningCodes,
            'assets[] must not carry orphaned refs; found: '.json_encode(array_filter($out->warnings, fn ($w) => $w->code === 'orphaned_asset'), JSON_PRETTY_PRINT),
        );
    }
}
