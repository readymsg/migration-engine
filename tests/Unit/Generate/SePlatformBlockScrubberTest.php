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
use App\Data\ScrubIssue;
use App\Data\ScrubKind;
use App\Services\Generate\Assembler;
use App\Services\Generate\BlockCoercer;
use App\Services\Generate\SePlatformBlockScrubber;
use App\Services\Schema\DefaultPuckComponentSchema;
use JsonException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

// Two validation halves for the SE-scrub Slice A, both required (per
// user spec): removing-the-ad without the no-false-positive proof is
// half the slice.
//
// GATE 1 — tbirdhoops: prove the scrubber REMOVES the SE-promo ads.
//   Home page's block #1 (Columns of 3 stale-countdown Cards) and
//   block #5 (ButtonGroup with 3 SE-promo buttons) MUST be dropped.
//   scrub_issues_by_slug records exactly those drops with reasons.
//   EVERY other block on EVERY page is byte-for-byte identical.
//
// GATE 2 — cjfl + langdon + tenacity: prove the scrubber DOESN'T
//   touch real org content. Zero scrubs. If any of these fire scrubs,
//   the label/href/countdown detection over-fired on legitimate copy —
//   silent-loss pointed at the wrong target.
//
// Both fixtures are captured real-Sonnet outputs. Scrubber runs
// deterministically against the assembler's output — no LLM, no
// network, purely code + captured JSON.
final class SePlatformBlockScrubberTest extends TestCase
{
    private function scrubber(): SePlatformBlockScrubber
    {
        return new SePlatformBlockScrubber;
    }

    private function assembleFixture(string $fixtureStem): AssemblyResult
    {
        $path = base_path("tests/Fixtures/blockfill/{$fixtureStem}.json");
        if (! is_file($path)) {
            throw new RuntimeException("fixture not found: {$path}");
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
        $blockFillResult = BlockFillResult::from($decoded);

        $schema = new DefaultPuckComponentSchema;

        return (new Assembler(new BlockCoercer($schema)))->run($blockFillResult);
    }

    #[Test]
    public function tbirdhoops_home_drops_onl_y_the_two_known_se_blocks_everything_else_unchanged(): void
    {
        // Baseline assemble the tbirdhoops fixture — Home page has 20
        // blocks including the SE-promo ButtonGroup (#5) and a Columns
        // of stale countdowns (#1).
        $baseline = $this->assembleFixture('tbirdhoops');

        $homeBaseline = $this->pageForSlug($baseline, 'page-7188115');
        $this->assertNotNull($homeBaseline, 'tbirdhoops Home must be in the baseline assembly');
        $baselineHomeBlockCount = count($homeBaseline->content);

        // Precondition: baseline has the SE-promo ButtonGroup at block #5
        // and the countdown Columns at block #1. If a future fixture
        // regeneration removes these upstream, this test becomes a
        // baseline check without work to do — surface that clearly.
        $this->assertSame('Columns', $homeBaseline->content[1]['type'] ?? null, 'precondition: block #1 is Columns');
        $this->assertSame('ButtonGroup', $homeBaseline->content[5]['type'] ?? null, 'precondition: block #5 is ButtonGroup');

        // Run the scrubber.
        $scrubbed = $this->scrubber()->run($baseline);

        $home = $this->pageForSlug($scrubbed, 'page-7188115');
        $this->assertNotNull($home);

        // ASSERTION 1: home has exactly 2 fewer blocks.
        $this->assertSame(
            $baselineHomeBlockCount - 2,
            count($home->content),
            'Home MUST drop exactly 2 blocks (Columns of countdowns + SE-promo ButtonGroup)'
        );

        // ASSERTION 2: scrub_issues_by_slug records exactly those two drops.
        $issues = $scrubbed->scrub_issues_by_slug['page-7188115'] ?? [];
        $this->assertCount(2, $issues, 'exactly 2 scrub issues recorded for Home');

        /** @var ScrubIssue $issue1 */
        $issue1 = $issues[0];
        $this->assertSame(1, $issue1->block_index);
        $this->assertSame('Columns', $issue1->component_type);
        $this->assertSame(ScrubKind::StaleCountdown, $issue1->kind);
        $this->assertStringContainsString('countdown', strtolower($issue1->reason));

        /** @var ScrubIssue $issue2 */
        $issue2 = $issues[1];
        $this->assertSame(5, $issue2->block_index);
        $this->assertSame('ButtonGroup', $issue2->component_type);
        $this->assertSame(ScrubKind::SePromoHref, $issue2->kind);
        $this->assertStringContainsString('SE-promo', $issue2->reason);

        // ASSERTION 3: every other block on Home is byte-for-byte identical.
        // Rebuild baseline sans blocks 1 and 5; compare against scrubbed.
        $baselineMinusScrubbed = array_values(array_filter(
            $homeBaseline->content,
            static fn (mixed $_, int $i): bool => $i !== 1 && $i !== 5,
            ARRAY_FILTER_USE_BOTH,
        ));
        $this->assertSame(
            $baselineMinusScrubbed,
            $home->content,
            'every non-scrubbed block on Home must be byte-for-byte identical'
        );
    }

    #[Test]
    public function tbirdhoops_non_home_pages_are_byte_for_byte_identical_after_scrub(): void
    {
        // Only Home has SE-promo/countdown content in the tbirdhoops
        // fixture. Every OTHER page must survive the scrubber unchanged.
        $baseline = $this->assembleFixture('tbirdhoops');
        $scrubbed = $this->scrubber()->run($baseline);

        $baselineBySlug = $this->pagesBySlug($baseline);
        $scrubbedBySlug = $this->pagesBySlug($scrubbed);

        $this->assertSame(
            array_keys($baselineBySlug),
            array_keys($scrubbedBySlug),
            'scrubber must not add or remove pages, only mutate blocks within pages'
        );

        foreach ($baselineBySlug as $slug => $baselinePage) {
            if ($slug === 'page-7188115') {
                continue; // Home has known scrubs — covered by other test.
            }
            $this->assertSame(
                $baselinePage->content,
                $scrubbedBySlug[$slug]->content,
                "page {$slug} must be byte-for-byte identical after scrub (no false positives)"
            );
            $this->assertArrayNotHasKey(
                $slug,
                $scrubbed->scrub_issues_by_slug,
                "page {$slug} must have zero scrub_issues"
            );
        }
    }

    #[Test]
    public function cjfl_zero_scrubs_no_false_positives_on_real_org_content(): void
    {
        // Gate 2, half 1: cjfl has 31 pages of real Canadian Junior
        // Football League content. Zero SE-promo. Scrubber must not
        // fire on ANY page. This is the false-positive proof — the
        // detection layers must not touch legitimate org content that
        // happens to mention sports leagues.
        $baseline = $this->assembleFixture('cjfl');
        $scrubbed = $this->scrubber()->run($baseline);

        $this->assertSame(
            [],
            $scrubbed->scrub_issues_by_slug,
            'cjfl must produce ZERO scrub_issues — no SE-promo in real CJFL content'
        );

        // Byte-for-byte identity across every page.
        $baselineBySlug = $this->pagesBySlug($baseline);
        $scrubbedBySlug = $this->pagesBySlug($scrubbed);
        foreach ($baselineBySlug as $slug => $baselinePage) {
            $this->assertSame(
                $baselinePage->content,
                $scrubbedBySlug[$slug]->content,
                "cjfl page {$slug} content changed — false-positive scrub"
            );
        }
    }

    #[Test]
    public function langdondiamonds_zero_scrubs_even_with_help_sportsengine_com_org_links(): void
    {
        // Gate 2, half 2: langdon's "For Coaches" page (page-7507234)
        // block #9 (Columns) contains SEVEN legitimate hrefs to
        // help.sportsengine.com articles — the org's own coach-help
        // content. These are ORG-authored links, not SE-injected
        // promo. Layer 1's SE_PROMO_HREF_PATTERNS is NARROWER than
        // SePlatformContentDetector::SE_PLATFORM_PATTERNS on purpose
        // for exactly this case: help.sportsengine.com is out of the
        // promo set (only app-store + solutions are in).
        //
        // Scrubber MUST not fire on these. If it does, Layer 1's
        // pattern set widened accidentally — surface that clearly.
        $baseline = $this->assembleFixture('langdondiamonds');
        $scrubbed = $this->scrubber()->run($baseline);

        $this->assertSame(
            [],
            $scrubbed->scrub_issues_by_slug,
            'langdondiamonds must produce ZERO scrub_issues — help.sportsengine.com '
            .'links on the Coaches page are LEGITIMATE org content, not promo'
        );

        // Precondition sanity: the Coaches page DOES have help.sportsengine.com
        // hrefs — if the fixture regenerated without them, the gate loses its
        // teeth without us knowing.
        $coaches = $this->pageForSlug($baseline, 'page-7507234');
        if ($coaches !== null) {
            $serialized = json_encode($coaches->content);
            $this->assertIsString($serialized);
            $this->assertStringContainsString(
                'help.sportsengine.com',
                (string) $serialized,
                'precondition: Coaches page must have help.sportsengine.com links so the '
                .'zero-scrub assertion above is meaningful'
            );
        }
    }

    #[Test]
    public function tenacityvolleyball_zero_scrubs(): void
    {
        // Gate 2, half 3: tenacity is a third real org fixture with
        // help.sportsengine.com links on their coach-help page.
        // Cross-fixture confirmation that the zero-scrub property
        // isn't cjfl-specific.
        $baseline = $this->assembleFixture('tenacityvolleyball');
        $scrubbed = $this->scrubber()->run($baseline);

        $this->assertSame(
            [],
            $scrubbed->scrub_issues_by_slug,
            'tenacityvolleyball must produce ZERO scrub_issues'
        );
    }

    #[Test]
    public function decorated_countdown_with_markdown_bold_wrappers_is_scrubbed(): void
    {
        // THE regression this test guards. Firecrawl captures SE's live
        // countdown widget as `<strong>N</strong> Days …` which
        // renders in markdown as `**N** Days …`. The block-fill agent
        // (per its faithfulness rule) copies this verbatim into
        // Card.body. The scrubber's Layer 3 countdown regex must catch
        // BOTH the zero-state form (fixture: `0 Days 0 Hours …`) AND
        // the decorated form (live: `**55** Days **10** Hours **54**
        // Minutes **46** Seconds`). The decorated form is the one that
        // slipped through hosted conv-yopWOw1rtVZRjf2R and rendered
        // `**55**` literally in the preview.
        //
        // If this test fails, the scrubber's STALE_COUNTDOWN_PATTERN
        // doesn't tolerate optional emphasis wrappers around the
        // numbers — the exact bug this expansion closes.
        $columnsOfCountdowns = [
            'type' => 'Columns',
            'props' => [
                'columns' => [
                    [
                        'children' => [[
                            'type' => 'Card',
                            'props' => [
                                'title' => 'Flight Tryouts',
                                'body' => '**55** Days **10** Hours **54** Minutes **46** Seconds',
                            ],
                        ]],
                    ],
                    [
                        'children' => [[
                            'type' => 'Card',
                            'props' => [
                                'title' => 'Thunderbird Assessments',
                                'body' => '**12** Days **03** Hours **21** Minutes **09** Seconds',
                            ],
                        ]],
                    ],
                    [
                        'children' => [[
                            'type' => 'Card',
                            'props' => [
                                'title' => 'Winter Basketball starts again in',
                                'body' => '**121** Days **04** Hours **17** Minutes **55** Seconds',
                            ],
                        ]],
                    ],
                ],
            ],
        ];

        // A legit Card ALONGSIDE the countdown block — must survive
        // unchanged. Anything not matching the countdown pattern is
        // out-of-scope for the scrubber.
        $legitCard = [
            'type' => 'Card',
            'props' => [
                'title' => 'Read the latest news',
                'body' => 'Weekly recaps and player interviews from the Thunderbirds beat.',
            ],
        ];

        $puck = new PuckOutput(
            page_slug: 'page-home',
            content: [$columnsOfCountdowns, $legitCard],
            root: ['title' => 'Home'],
            zones: [],
        );
        $assembly = new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, [$puck]),
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

        $scrubbed = $this->scrubber()->run($assembly);

        $page = $this->pageForSlug($scrubbed, 'page-home');
        $this->assertNotNull($page);

        // 1. The whole Columns block dropped (all 3 nested Cards
        //    matched the countdown pattern → every column emptied →
        //    Columns dropped). Verified by the existing nested-Card
        //    logic in scrubColumnsBlock.
        $this->assertCount(
            1,
            $page->content,
            'decorated-countdown Columns must be dropped, leaving only the legit Card'
        );
        $this->assertSame('Card', $page->content[0]['type']);
        $this->assertSame($legitCard, $page->content[0], 'legit Card must be byte-for-byte unchanged');

        // 2. scrub_issues records the drop with the StaleCountdown kind
        //    and mentions all three countdown titles by name.
        $issues = $scrubbed->scrub_issues_by_slug['page-home'] ?? [];
        $this->assertCount(1, $issues);
        /** @var ScrubIssue $issue */
        $issue = $issues[0];
        $this->assertSame(0, $issue->block_index);
        $this->assertSame('Columns', $issue->component_type);
        $this->assertSame(ScrubKind::StaleCountdown, $issue->kind);
        $this->assertStringContainsString('Flight Tryouts', $issue->dropped_content_summary);
        $this->assertStringContainsString('Thunderbird Assessments', $issue->dropped_content_summary);
        $this->assertStringContainsString('Winter Basketball', $issue->dropped_content_summary);
    }

    #[Test]
    public function decorated_countdown_top_level_card_is_scrubbed(): void
    {
        // Same shape but a top-level Card (not nested under Columns).
        // Layer 3 applies at BOTH nesting depths; this test proves the
        // top-level path handles decorated countdowns too.
        $countdownCard = [
            'type' => 'Card',
            'props' => [
                'title' => 'Registration closes in',
                'body' => '**7** Days **14** Hours **23** Minutes **05** Seconds',
            ],
        ];
        $puck = new PuckOutput(
            page_slug: 'page-home',
            content: [$countdownCard],
            root: ['title' => 'Home'],
            zones: [],
        );
        $assembly = new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, [$puck]),
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

        $scrubbed = $this->scrubber()->run($assembly);
        $page = $this->pageForSlug($scrubbed, 'page-home');
        $this->assertNotNull($page);
        $this->assertSame([], $page->content, 'decorated-countdown Card must drop');
        $this->assertCount(1, $scrubbed->scrub_issues_by_slug['page-home'] ?? []);
    }

    #[Test]
    public function empty_assembly_result_scrubber_produces_empty_scrub_issues(): void
    {
        // Sanity: scrubber must not throw on an assembly with zero
        // pages (upstream Failed conversion, etc.).
        $empty = new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, []),
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

        $result = $this->scrubber()->run($empty);

        $this->assertSame(0, $result->pages->count());
        $this->assertSame([], $result->scrub_issues_by_slug);
    }

    private function pageForSlug(AssemblyResult $result, string $slug): ?PuckOutput
    {
        foreach ($result->pages->items() as $page) {
            /** @var PuckOutput $page */
            if ($page->page_slug === $slug) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @return array<string, PuckOutput>
     */
    private function pagesBySlug(AssemblyResult $result): array
    {
        /** @var array<string, PuckOutput> $out */
        $out = [];
        foreach ($result->pages->items() as $page) {
            /** @var PuckOutput $page */
            $out[$page->page_slug] = $page;
        }

        return $out;
    }
}
