<?php

declare(strict_types=1);

namespace Tests\Unit\Generate;

use App\Data\AssemblyCoercion;
use App\Data\AssemblyFailure;
use App\Data\AssemblyStatus;
use App\Data\BlockFillFailure;
use App\Data\BlockFillResult;
use App\Data\BlockFillStatus;
use App\Data\FilledBlock;
use App\Data\FilledPage;
use App\Data\GlobalStyleBrief;
use App\Data\NavItem;
use App\Data\PuckOutput;
use App\Services\Generate\Assembler;
use App\Services\Generate\BlockCoercer;
use App\Services\Schema\DefaultPuckComponentSchema;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

// Assembler reconciliation tie-out. Validates the N-in-N-out posture
// (every FilledPage → PuckOutput OR AssemblyFailure exactly once),
// status transitions, and that platform_dynamic pages (legitimately
// absent from BlockFillResult.pages) do NOT phantom-fail.
final class AssemblerTest extends TestCase
{
    private function assembler(): Assembler
    {
        return new Assembler(new BlockCoercer(new DefaultPuckComponentSchema));
    }

    private function emptyBrief(): GlobalStyleBrief
    {
        return new GlobalStyleBrief(
            brand_voice: '',
            palette: [],
            layout_conventions: [],
            nav: new DataCollection(NavItem::class, []),
        );
    }

    /**
     * @param  array<int, FilledPage>  $pages
     * @param  array<int, BlockFillFailure>  $failures
     */
    private function blockFillResult(array $pages, array $failures = [], BlockFillStatus $status = BlockFillStatus::Complete): BlockFillResult
    {
        return new BlockFillResult(
            style_brief: $this->emptyBrief(),
            pages: new DataCollection(FilledPage::class, $pages),
            failures: new DataCollection(BlockFillFailure::class, $failures),
            status: $status,
        );
    }

    /**
     * @param  array<int, FilledBlock>  $blocks
     */
    private function page(string $slug, array $blocks, string $title = 'Test'): FilledPage
    {
        return new FilledPage(
            page_slug: $slug,
            page_title: $title,
            nav_order: 0,
            blocks: new DataCollection(FilledBlock::class, $blocks),
            self_assessment: '',
            confidence: 0.9,
        );
    }

    #[Test]
    public function clean_run_emits_one_puck_per_filled_page_status_complete(): void
    {
        $input = $this->blockFillResult([
            $this->page('page-1', [
                new FilledBlock('Hero', ['heading' => 'Welcome'], '', null),
                new FilledBlock('Text', ['body' => 'About us'], '', null),
            ]),
            $this->page('page-2', [
                new FilledBlock('Heading', ['text' => 'News', 'level' => 'h1'], '', null),
            ]),
        ]);

        $result = $this->assembler()->run($input);

        $this->assertSame(AssemblyStatus::Complete, $result->status);
        $this->assertCount(2, $result->pages);
        $this->assertCount(0, $result->failures);
        $this->assertSame([], $result->block_issues_by_slug);

        /** @var PuckOutput $page1 */
        $page1 = $result->pages->items()[0];
        $this->assertSame('page-1', $page1->page_slug);
        $this->assertSame('Hero', $page1->content[0]['type']);
        $this->assertSame('Text', $page1->content[1]['type']);
        $this->assertSame('Test', $page1->root['title']);
    }

    #[Test]
    public function platform_dynamic_pages_absent_from_block_fill_input_do_not_phantom_fail(): void
    {
        // Diff universe is EXACTLY BlockFillResult.pages. If a
        // platform_dynamic page (Schedule, Roster, etc.) was filtered
        // at IR-pass time and never reached block-fill, the assembler
        // must NOT consult SitePlan/ledger and must NOT phantom-fail
        // those pages. They're a separate seam (PlatformBlockRenderer).
        $input = $this->blockFillResult([
            $this->page('page-content-1', [
                new FilledBlock('Text', ['body' => 'content'], '', null),
            ]),
        ]);
        // No upstream failures, no mention of platform_dynamic slugs.

        $result = $this->assembler()->run($input);

        $this->assertSame(AssemblyStatus::Complete, $result->status);
        $this->assertCount(1, $result->pages);
        $this->assertCount(0, $result->failures);
    }

    #[Test]
    public function every_filled_page_lands_in_pages_or_failures_exactly_once(): void
    {
        // Two FilledPages: one clean, one with all blocks dropped.
        // Plus one upstream BlockFillFailure. Expected: 1 PuckOutput +
        // 2 AssemblyFailures, 0 overlap.
        $input = $this->blockFillResult(
            pages: [
                $this->page('page-ok', [
                    new FilledBlock('Hero', ['heading' => 'Hi'], '', null),
                ], title: 'OK'),
                $this->page('page-empty', [
                    // Card without title → drops. All blocks drop → page failure.
                    new FilledBlock('Card', ['body' => 'no title'], '', null),
                ], title: 'Empty'),
            ],
            failures: [
                new BlockFillFailure(
                    page_slug: 'page-upstream',
                    page_title: 'Upstream',
                    page_node_id: 99,
                    reason: 'sonnet 5xx',
                ),
            ],
            status: BlockFillStatus::Partial,
        );

        $result = $this->assembler()->run($input);

        $this->assertSame(AssemblyStatus::Partial, $result->status);
        $this->assertCount(1, $result->pages);
        $this->assertCount(2, $result->failures);

        // Tie-out: every input slug accounted for, exactly once.
        $pageSlugs = array_map(static fn (PuckOutput $p): string => $p->page_slug, $result->pages->items());
        $failureSlugs = array_map(static fn (AssemblyFailure $f): string => $f->page_slug, $result->failures->items());
        $all = array_merge($pageSlugs, $failureSlugs);
        sort($all);
        $this->assertSame(['page-empty', 'page-ok', 'page-upstream'], $all);
        $this->assertSame([], array_intersect($pageSlugs, $failureSlugs));
    }

    #[Test]
    public function page_with_zero_surviving_blocks_becomes_assembly_failure_no_blank_puck(): void
    {
        $input = $this->blockFillResult([
            $this->page('page-all-broken', [
                new FilledBlock('Card', ['body' => 'no title'], '', null),
                new FilledBlock('Sidebar', ['foo' => 'bar'], '', null),
            ]),
        ]);

        $result = $this->assembler()->run($input);

        $this->assertSame(AssemblyStatus::Partial, $result->status);
        $this->assertCount(0, $result->pages, 'must NOT emit a blank PuckOutput');
        $this->assertCount(1, $result->failures);

        /** @var AssemblyFailure $fail */
        $fail = $result->failures->items()[0];
        $this->assertSame('page-all-broken', $fail->page_slug);
        $this->assertStringContainsString('every block on this page was dropped', $fail->reason);

        // The per-block drop issues are still surfaced on the page's
        // sidecar entry for review.
        $this->assertArrayHasKey('page-all-broken', $result->block_issues_by_slug);
        $this->assertCount(2, $result->block_issues_by_slug['page-all-broken']);
    }

    #[Test]
    public function partial_page_emits_puck_with_block_issues_sidecar(): void
    {
        // Two-block page: one survives, one drops. Page emits a
        // PuckOutput with one block, and one entry in block_issues_by_slug.
        $input = $this->blockFillResult([
            $this->page('page-partial', [
                new FilledBlock('Hero', ['heading' => 'Welcome'], '', null),    // survives
                new FilledBlock('Card', ['body' => 'no title'], '', null),       // drops
            ]),
        ]);

        $result = $this->assembler()->run($input);

        $this->assertSame(AssemblyStatus::Partial, $result->status);
        $this->assertCount(1, $result->pages);
        $this->assertCount(0, $result->failures);

        /** @var PuckOutput $puck */
        $puck = $result->pages->items()[0];
        $this->assertCount(1, $puck->content);
        $this->assertSame('Hero', $puck->content[0]['type']);

        $this->assertArrayHasKey('page-partial', $result->block_issues_by_slug);
        $issues = $result->block_issues_by_slug['page-partial'];
        $this->assertCount(1, $issues);
        $this->assertSame(1, $issues[0]->block_index, 'second block (index 1) dropped');
        $this->assertSame(AssemblyCoercion::Drop, $issues[0]->coercion);
    }

    #[Test]
    public function substitution_only_page_is_partial_with_block_issue_but_no_failure(): void
    {
        // A page where every block survives but one had a select-out-
        // of-options substitution. Status = Partial (block_issues
        // non-empty), failures = 0, page emitted.
        $input = $this->blockFillResult([
            $this->page('page-sub', [
                new FilledBlock('Heading', ['text' => 'Welcome', 'level' => 'h7'], '', null),
            ]),
        ]);

        $result = $this->assembler()->run($input);

        $this->assertSame(AssemblyStatus::Partial, $result->status);
        $this->assertCount(1, $result->pages);
        $this->assertCount(0, $result->failures);
        $this->assertArrayHasKey('page-sub', $result->block_issues_by_slug);
        $this->assertSame(AssemblyCoercion::Substitution, $result->block_issues_by_slug['page-sub'][0]->coercion);
    }

    #[Test]
    public function upstream_block_fill_failed_status_propagates_to_assembly_failed(): void
    {
        $input = $this->blockFillResult(
            pages: [],
            failures: [
                new BlockFillFailure(
                    page_slug: 'page-1',
                    page_title: 'Page 1',
                    page_node_id: 1,
                    reason: 'ir-pass-failure: capacity exceeded',
                ),
            ],
            status: BlockFillStatus::Failed,
        );

        $result = $this->assembler()->run($input);

        $this->assertSame(AssemblyStatus::Failed, $result->status);
        $this->assertCount(0, $result->pages);
        $this->assertCount(1, $result->failures);
        /** @var AssemblyFailure $f */
        $f = $result->failures->items()[0];
        $this->assertStringStartsWith('block-fill-failure: ', $f->reason);
    }

    #[Test]
    public function block_fill_failures_pass_through_as_assembly_failures_with_prefix(): void
    {
        $input = $this->blockFillResult(
            pages: [
                $this->page('page-content', [
                    new FilledBlock('Text', ['body' => 'ok'], '', null),
                ]),
            ],
            failures: [
                new BlockFillFailure(
                    page_slug: 'page-broken',
                    page_title: 'Broken',
                    page_node_id: 5,
                    reason: 'sonnet timeout',
                ),
            ],
            status: BlockFillStatus::Partial,
        );

        $result = $this->assembler()->run($input);

        $this->assertSame(AssemblyStatus::Partial, $result->status);
        $this->assertCount(1, $result->pages);
        $this->assertCount(1, $result->failures);

        /** @var AssemblyFailure $f */
        $f = $result->failures->items()[0];
        $this->assertSame('page-broken', $f->page_slug);
        $this->assertSame('block-fill-failure: sonnet timeout', $f->reason);
        $this->assertSame(5, $f->page_node_id);
    }

    #[Test]
    public function nested_columns_grid_survives_with_card_children_assembled(): void
    {
        // The real-world tbirdhoops Board page shape: Columns wrapping
        // multiple Card children. Validate the recursive assembly emits
        // Puck-shaped children with the right type+props.
        $input = $this->blockFillResult([
            $this->page('page-board', [
                new FilledBlock('Heading', ['text' => 'Our Board', 'level' => 'h1'], '', null),
                new FilledBlock('Columns', [
                    'columns' => [
                        [
                            'width' => '1/3',
                            'children' => [
                                ['component_type' => 'Card', 'props' => ['title' => 'President', 'body' => 'Scott']],
                                ['component_type' => 'Card', 'props' => ['title' => 'Treasurer', 'body' => 'Janet']],
                            ],
                        ],
                    ],
                ], '', null),
            ]),
        ]);

        $result = $this->assembler()->run($input);

        $this->assertSame(AssemblyStatus::Complete, $result->status);
        /** @var PuckOutput $page */
        $page = $result->pages->items()[0];
        $this->assertCount(2, $page->content);
        $this->assertSame('Columns', $page->content[1]['type']);

        $children = $page->content[1]['props']['columns'][0]['children'];
        $this->assertCount(2, $children);
        $this->assertSame('Card', $children[0]['type']);
        $this->assertSame('President', $children[0]['props']['title']);
        $this->assertSame('Card', $children[1]['type']);
        $this->assertSame('Treasurer', $children[1]['props']['title']);
    }
}
