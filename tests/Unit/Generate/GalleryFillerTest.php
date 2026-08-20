<?php

declare(strict_types=1);

namespace Tests\Unit\Generate;

use App\Data\AssemblyFailure;
use App\Data\AssemblyResult;
use App\Data\AssemblyStatus;
use App\Data\BlockFillResult;
use App\Data\GlobalStyleBrief;
use App\Data\NavItem;
use App\Data\PuckOutput;
use App\Data\ScrubKind;
use App\Services\Generate\Assembler;
use App\Services\Generate\BlockCoercer;
use App\Services\Generate\GalleryFiller;
use App\Services\Schema\DefaultPuckComponentSchema;
use JsonException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

// Post-assembly gallery back-fill tests.
//
// The GalleryFiller exists because block-fill (Sonnet) truncates
// gallery image lists silently. The traced failure on tbirdhoops
// Home: 8 of 9 source galleries received 1 image out of 4-14 present
// in source markdown, with no scrub issue.
//
// Two invariants the filler must hold:
//   1. When a source gallery matches a truncated Puck Columns block,
//      the block is REPLACED with a native Gallery block carrying
//      every source image. An informational ScrubKind::GalleryFilled
//      is recorded so nothing changes invisibly.
//   2. When a source gallery can't be filled (no images, or empty),
//      a ScrubKind::GalleryFillFailure entry is recorded. Failures
//      are visible; silent absence is FORBIDDEN.
final class GalleryFillerTest extends TestCase
{
    private function filler(): GalleryFiller
    {
        return new GalleryFiller;
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

    #[Test]
    public function truncated_columns_gallery_is_backfilled_with_full_source_images(): void
    {
        // Puck output has ONE image, source has THREE.
        $page = new PuckOutput(
            page_slug: 'home',
            content: [
                [
                    'type' => 'Columns',
                    'props' => [
                        'columns' => [
                            ['children' => [
                                ['type' => 'Heading', 'props' => ['text' => 'Season Pics', 'level' => 'h3']],
                                ['type' => 'Image', 'props' => ['src' => 'https://x/1.jpg', 'alt' => 'Season Pics photo 1']],
                            ]],
                        ],
                    ],
                ],
            ],
            root: ['title' => 'Home'],
        );
        $markdown = <<<'MD'
### Season Pics

- ![](https://x/1.jpg)
- ![](https://x/2.jpg)
- ![](https://x/3.jpg)
MD;

        $out = $this->filler()->run($this->emptyAssembly($page), ['home' => $markdown]);
        $updated = $out->pages->items()[0];
        $this->assertInstanceOf(PuckOutput::class, $updated);
        $this->assertCount(1, $updated->content);
        $block = $updated->content[0];
        $this->assertSame('Gallery', $block['type']);
        $this->assertSame('Season Pics', $block['props']['title']);
        $this->assertCount(3, $block['props']['items']);
        $srcs = array_map(static fn (array $it) => $it['src'], $block['props']['items']);
        $this->assertSame(['https://x/1.jpg', 'https://x/2.jpg', 'https://x/3.jpg'], $srcs);

        // Informational scrub entry MUST be recorded.
        $issues = $out->scrub_issues_by_slug['home'] ?? [];
        $this->assertCount(1, $issues);
        $this->assertSame(ScrubKind::GalleryFilled, $issues[0]->kind);
        $this->assertStringContainsString('1/3 images', $issues[0]->reason);
        $this->assertStringContainsString('Season Pics', $issues[0]->dropped_content_summary);
    }

    #[Test]
    public function source_gallery_with_no_matching_puck_block_is_appended_and_recorded(): void
    {
        $page = new PuckOutput(
            page_slug: 'home',
            content: [
                ['type' => 'Hero', 'props' => ['heading' => 'Welcome']],
            ],
            root: ['title' => 'Home'],
        );
        $markdown = <<<'MD'
### Unrelated Gallery

- ![](https://y/a.jpg)
- ![](https://y/b.jpg)
MD;

        $out = $this->filler()->run($this->emptyAssembly($page), ['home' => $markdown]);
        $updated = $out->pages->items()[0];
        $this->assertCount(2, $updated->content, 'unmatched gallery should be appended');
        $inserted = $updated->content[1];
        $this->assertSame('Gallery', $inserted['type']);
        $this->assertCount(2, $inserted['props']['items']);

        $issues = $out->scrub_issues_by_slug['home'] ?? [];
        $this->assertCount(1, $issues);
        $this->assertSame(ScrubKind::GalleryFilled, $issues[0]->kind);
        $this->assertStringContainsString('no matching Puck block', $issues[0]->reason);
    }

    #[Test]
    public function page_without_gallery_shaped_blocks_is_unchanged(): void
    {
        $page = new PuckOutput(
            page_slug: 'home',
            content: [
                ['type' => 'Text', 'props' => ['body' => 'not a gallery']],
                ['type' => 'Card', 'props' => ['title' => 'not a gallery', 'body' => 'body']],
            ],
            root: ['title' => 'Home'],
        );
        // Empty markdown → nothing to fill.
        $out = $this->filler()->run($this->emptyAssembly($page), ['home' => '']);
        $updated = $out->pages->items()[0];
        $this->assertSame($page->content, $updated->content);
        $this->assertSame([], $out->scrub_issues_by_slug);
    }

    #[Test]
    public function fully_populated_gallery_block_is_left_alone(): void
    {
        $page = new PuckOutput(
            page_slug: 'home',
            content: [
                [
                    'type' => 'Columns',
                    'props' => [
                        'columns' => [
                            ['children' => [
                                ['type' => 'Heading', 'props' => ['text' => 'Season Pics', 'level' => 'h3']],
                                ['type' => 'Image', 'props' => ['src' => 'https://x/1.jpg', 'alt' => 'a']],
                            ]],
                            ['children' => [
                                ['type' => 'Image', 'props' => ['src' => 'https://x/2.jpg', 'alt' => 'b']],
                            ]],
                        ],
                    ],
                ],
            ],
            root: ['title' => 'Home'],
        );
        $markdown = <<<'MD'
### Season Pics

- ![](https://x/1.jpg)
- ![](https://x/2.jpg)
MD;

        $out = $this->filler()->run($this->emptyAssembly($page), ['home' => $markdown]);
        $updated = $out->pages->items()[0];
        // Block-fill got this one right; no replacement needed.
        $this->assertSame('Columns', $updated->content[0]['type']);
        $this->assertSame([], $out->scrub_issues_by_slug);
    }

    #[Test]
    public function empty_source_gallery_generates_visible_fill_failure(): void
    {
        // A source heading followed by non-image content — treated as a
        // non-gallery, so it should NOT surface. The filler should not
        // create spurious entries for headings-that-look-like-galleries.
        $page = new PuckOutput(
            page_slug: 'home',
            content: [['type' => 'Text', 'props' => ['body' => 'ok']]],
            root: ['title' => 'Home'],
        );
        $markdown = <<<'MD'
### Not A Gallery

Some text follows, not images.
MD;
        $out = $this->filler()->run($this->emptyAssembly($page), ['home' => $markdown]);
        $this->assertSame([], $out->scrub_issues_by_slug, 'heading without image bullets is not a gallery');
    }

    #[Test]
    public function tbirdhoops_home_fixture_gains_lakota_west_gallery_from_seven_source_images(): void
    {
        $bf = $this->loadFixture(base_path('tests/Fixtures/blockfill/tbirdhoops.json'));
        $schema = new DefaultPuckComponentSchema;
        $assembly = (new Assembler(new BlockCoercer($schema)))->run($bf);

        $home = null;
        foreach ($assembly->pages->items() as $p) {
            if ($p->page_slug === 'page-7188115') {
                $home = $p;
            }
        }
        $this->assertNotNull($home);

        $markdown = $this->tbirdhoopsHomeMarkdown();
        $out = (new GalleryFiller)->run($assembly, ['page-7188115' => $markdown]);
        $updatedHome = null;
        foreach ($out->pages->items() as $p) {
            if ($p->page_slug === 'page-7188115') {
                $updatedHome = $p;
            }
        }
        $this->assertNotNull($updatedHome);

        // Locate the "Lakota West Basketball Camp & Community" gallery.
        $lakotaWest = null;
        foreach ($updatedHome->content as $block) {
            if (($block['type'] ?? null) === 'Gallery'
                && ($block['props']['title'] ?? null) === 'Lakota West Basketball Camp & Community') {
                $lakotaWest = $block;
                break;
            }
        }
        $this->assertNotNull($lakotaWest, 'Lakota West gallery must be present as a Gallery block after fill');
        $this->assertCount(7, $lakotaWest['props']['items'], 'source has 7 images; fill must carry them all');
    }

    /** @return array<int, PuckOutput> */
    private function loadFixture(string $path): BlockFillResult
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

    private function tbirdhoopsHomeMarkdown(): string
    {
        $path = storage_path('app/private/orgs/ngin-63620/scrapes/4ef3d348e1a5db523dc9196110cb62b84baa3f76.json');
        if (! is_file($path)) {
            $this->markTestSkipped('tbirdhoops home scrape not on disk');
        }
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        $md = $decoded['markdown'] ?? '';

        return is_string($md) ? $md : '';
    }
}
