<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\AssemblyBlockIssue;
use App\Data\AssemblyFailure;
use App\Data\AssemblyResult;
use App\Data\AssemblyStatus;
use App\Data\BlockFillFailure;
use App\Data\BlockFillResult;
use App\Data\BlockFillStatus;
use App\Data\FilledBlock;
use App\Data\FilledPage;
use App\Data\PuckOutput;
use Spatie\LaravelData\DataCollection;

// GENERATE stage 3 slice 2d orchestration. Deterministic — no LLM.
// Reads BlockFillResult and produces AssemblyResult: PuckOutputs ready
// for ProductClient.createDraftSite, plus any per-block coercion
// issues and any whole-page failures.
//
// FAITHFUL-REBUILD GUARANTEE chains across stages: every FilledPage in
// BlockFillResult.pages becomes a PuckOutput OR an AssemblyFailure,
// exactly once. Every BlockFillFailure passes through as an
// AssemblyFailure (reason prefixed 'block-fill-failure:'). Diff
// universe is EXACTLY BlockFillResult.pages — platform_dynamic pages
// are legitimately absent (filtered at IR-pass time) and the
// assembler MUST NOT phantom-fail them. PlatformBlockRenderer (slice
// 2e) is responsible for emitting their PuckOutputs from the
// SitePlan.ledger.
//
// A page where every block drops during coercion becomes an
// AssemblyFailure (NEVER a blank PuckOutput that would render empty).
// A page where SOME blocks survive emits a PuckOutput plus
// AssemblyBlockIssue entries on block_issues_by_slug — flagging the
// conversion as Partial.
final class Assembler
{
    public function __construct(
        private readonly BlockCoercer $coercer,
    ) {}

    public function run(BlockFillResult $blockFill): AssemblyResult
    {
        // Upstream abort — no FilledPages to process; surface every
        // upstream failure and mark Failed.
        if ($blockFill->status === BlockFillStatus::Failed) {
            return $this->failedFromUpstream($blockFill);
        }

        /** @var array<int, PuckOutput> $puckPages */
        $puckPages = [];
        /** @var array<int, AssemblyFailure> $failures */
        $failures = [];
        /** @var array<string, array<int, AssemblyBlockIssue>> $issuesBySlug */
        $issuesBySlug = [];

        /** @var array<int, FilledPage> $filledPages */
        $filledPages = $blockFill->pages->items();
        foreach ($filledPages as $page) {
            [$puck, $pageIssues, $pageFailure] = $this->assemblePage($page);
            if ($pageFailure !== null) {
                $failures[] = $pageFailure;
                if ($pageIssues !== []) {
                    // A page-level failure can still carry the
                    // per-block drop reasons for review.
                    $issuesBySlug[$page->page_slug] = $pageIssues;
                }

                continue;
            }
            if ($puck !== null) {
                $puckPages[] = $puck;
            }
            if ($pageIssues !== []) {
                $issuesBySlug[$page->page_slug] = $pageIssues;
            }
        }

        // Chain upstream block-fill failures through as AssemblyFailures
        // so the conversion log sees every page once across stages.
        /** @var array<int, BlockFillFailure> $upstream */
        $upstream = $blockFill->failures->items();
        foreach ($upstream as $f) {
            $failures[] = new AssemblyFailure(
                page_slug: $f->page_slug,
                page_title: $f->page_title,
                page_node_id: $f->page_node_id,
                reason: 'block-fill-failure: '.$f->reason,
            );
        }

        $status = $this->resolveStatus($blockFill, $failures, $issuesBySlug);

        return new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, $puckPages),
            failures: new DataCollection(AssemblyFailure::class, $failures),
            block_issues_by_slug: $issuesBySlug,
            status: $status,
        );
    }

    /**
     * @return array{0: ?PuckOutput, 1: array<int, AssemblyBlockIssue>, 2: ?AssemblyFailure}
     */
    private function assemblePage(FilledPage $page): array
    {
        /** @var array<int, array<string, mixed>> $content */
        $content = [];
        /** @var array<int, AssemblyBlockIssue> $issues */
        $issues = [];

        /** @var array<int, FilledBlock> $blocks */
        $blocks = $page->blocks->items();
        foreach (array_values($blocks) as $blockIndex => $block) {
            $result = $this->coercer->coerce($block->component_type, $block->props);

            foreach ($result->issues as $ci) {
                $issues[] = new AssemblyBlockIssue(
                    block_index: $blockIndex,
                    component_type: $ci->component_type,
                    coercion: $ci->coercion,
                    reason: $ci->reason,
                    path: $ci->path,
                );
            }

            if ($result->dropped) {
                continue;
            }

            $content[] = [
                'type' => $block->component_type,
                'props' => $result->coerced_props ?? [],
            ];
        }

        if ($content === []) {
            $reason = $blocks === []
                ? 'FilledPage had no blocks — nothing to assemble'
                : 'every block on this page was dropped during coercion ('
                    .count($issues).' issue'.(count($issues) === 1 ? '' : 's').')';

            return [
                null,
                $issues,
                new AssemblyFailure(
                    page_slug: $page->page_slug,
                    page_title: $page->page_title,
                    page_node_id: null,
                    reason: $reason,
                ),
            ];
        }

        $puck = new PuckOutput(
            page_slug: $page->page_slug,
            content: $content,
            root: ['title' => $page->page_title],
            zones: [],
        );

        return [$puck, $issues, null];
    }

    /**
     * @param  array<int, AssemblyFailure>  $failures
     * @param  array<string, array<int, AssemblyBlockIssue>>  $issuesBySlug
     */
    private function resolveStatus(BlockFillResult $blockFill, array $failures, array $issuesBySlug): AssemblyStatus
    {
        // Failed only when the upstream abort signal was set.
        if ($blockFill->status === BlockFillStatus::Failed) {
            return AssemblyStatus::Failed;
        }
        if ($failures !== [] || $issuesBySlug !== []) {
            return AssemblyStatus::Partial;
        }
        // Upstream Partial with zero downstream impact still degrades
        // to Partial (the upstream failures themselves are in
        // $failures above, so this branch fires when block-fill was
        // clean and the assembler ran clean).
        if ($blockFill->status === BlockFillStatus::Partial) {
            return AssemblyStatus::Partial;
        }

        return AssemblyStatus::Complete;
    }

    private function failedFromUpstream(BlockFillResult $blockFill): AssemblyResult
    {
        /** @var array<int, AssemblyFailure> $failures */
        $failures = [];
        /** @var array<int, BlockFillFailure> $items */
        $items = $blockFill->failures->items();
        foreach ($items as $f) {
            $failures[] = new AssemblyFailure(
                page_slug: $f->page_slug,
                page_title: $f->page_title,
                page_node_id: $f->page_node_id,
                reason: 'block-fill-failure: '.$f->reason,
            );
        }

        return new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, []),
            failures: new DataCollection(AssemblyFailure::class, $failures),
            block_issues_by_slug: [],
            status: AssemblyStatus::Failed,
        );
    }
}
