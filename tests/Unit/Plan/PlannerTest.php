<?php

declare(strict_types=1);

namespace Tests\Unit\Plan;

use App\Data\ClassificationResponse;
use App\Data\DecisionAction;
use App\Data\DecisionEntry;
use App\Data\InventoryPage;
use App\Services\Plan\RootNavPlanner;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Plan\FakeClassifierAgent;
use Tests\Support\Plan\RealManifests;
use Tests\TestCase;

final class PlannerTest extends TestCase
{
    #[Test]
    public function ledger_covers_every_page_for_stthomas_and_skips_external_and_dynamic(): void
    {
        $manifest = RealManifests::stthomas();
        $agent = new FakeClassifierAgent;
        $plan = (new RootNavPlanner($agent))->plan($manifest);

        // Every page in the inventoried tree has exactly one ledger entry.
        $entries = $plan->ledger->entries->items();
        $this->assertCount(18, $entries, 'stthomas has 7 top-level + 11 About Us children');

        // The deterministic dispositions are present.
        $dibs = $this->ledgerEntryFor($plan->ledger->entries->items(), 'https://www.stthomassoccer.com/dib_sessions/index');
        $this->assertNotNull($dibs);
        $this->assertSame(DecisionAction::Keep, $dibs->action);
        $this->assertSame(1.0, $dibs->confidence);
        $this->assertStringContainsString('SE third-party tool', $dibs->reason);

        $swag = $this->ledgerEntryByReasonFragment($entries, 'LinkNode external link');
        $this->assertNotNull($swag, 'Swag/Spirit Wear LinkNode should be in ledger as keep');
        $this->assertSame(DecisionAction::Keep, $swag->action);

        // External + dynamic + unknown pages are NEVER sent to the LLM —
        // ONLY kind=page is. stthomas has 16 Page kinds (the 18 tree nodes
        // minus 1 LinkNode minus 1 toolsLink-null).
        $this->assertCount(16, $agent->seen);
        foreach ($agent->seen as $page) {
            $this->assertSame('page', $page->kind, "LLM saw a non-page: {$page->label} ({$page->kind})");
        }

        // Batching: ≤ 20 per call. stthomas's 16 content pages fit in one batch.
        $this->assertCount(1, $agent->batches);
        $this->assertLessThanOrEqual(20, count($agent->batches[0]));

        // Nav contains the surviving top-level entries. With default
        // fake = keep@0.85, every top-level Page + Dibs (toolsLink keep)
        // ends up in nav: 6 Pages + 1 toolsLink = 7 nav items.
        $this->assertCount(7, $plan->nav);

        // Kept pages = all 18 (default fake keeps everything; dropped/parked
        // would shrink this).
        $this->assertCount(18, $plan->kept_pages);
    }

    #[Test]
    public function ledger_for_langdondiamonds_handles_waterworld_and_calendar(): void
    {
        $manifest = RealManifests::langdondiamonds();
        $agent = new FakeClassifierAgent;
        $plan = (new RootNavPlanner($agent))->plan($manifest);

        $entries = $plan->ledger->entries->items();
        // 13 top-level + 6 About Us children = 19 pages
        $this->assertCount(19, $entries);

        // The "Calendar" top-level is Calendar node_type — must classify
        // deterministically as `dynamic`, never sent to the LLM.
        $calendarEntries = array_values(array_filter(
            $entries,
            static fn (DecisionEntry $e) => str_contains($e->reason, 'Calendar'),
        ));
        $this->assertNotEmpty($calendarEntries);
        $this->assertSame(DecisionAction::Dynamic, $calendarEntries[0]->action);
        $this->assertSame(1.0, $calendarEntries[0]->confidence);

        // The Dibs toolsLink: deterministic keep.
        $dibs = $this->ledgerEntryFor($entries, 'https://www.langdondiamonds.ca/dib_sessions/index');
        $this->assertNotNull($dibs);
        $this->assertSame(DecisionAction::Keep, $dibs->action);

        // Only kind=page reaches the LLM. langdondiamonds has 17 Page +
        // 1 Calendar + 1 toolsLink-null = 17 LLM-bound entries.
        $this->assertCount(17, $agent->seen);
        foreach ($agent->seen as $page) {
            $this->assertSame('page', $page->kind);
        }
    }

    #[Test]
    public function low_confidence_drop_or_park_becomes_keep_with_model_reason_preserved(): void
    {
        // Faithful-rebuild bias: the model's "park" / "drop" at low confidence
        // is not enough to set a page aside in v1. The page is kept; the
        // model's verdict is preserved in the ledger entry so the human
        // reviewer can still see why it was flagged.
        $manifest = RealManifests::stthomas();
        $agent = new FakeClassifierAgent;
        $agent->respondWith(static fn (InventoryPage $p): ClassificationResponse => new ClassificationResponse(
            action: DecisionAction::Drop,
            confidence: 0.4,                 // below 0.80 threshold
            reason: 'looks stale',
        ));

        $plan = (new RootNavPlanner($agent))->plan($manifest);

        $llmEntries = array_values(array_filter(
            $plan->ledger->entries->items(),
            static fn (DecisionEntry $e) => str_contains($e->reason, 'recall-biased keep'),
        ));
        $this->assertCount(16, $llmEntries, 'all 16 ambiguous content pages should be kept under recall bias');
        foreach ($llmEntries as $entry) {
            $this->assertSame(DecisionAction::Keep, $entry->action);
            $this->assertStringContainsString('model wanted drop', $entry->reason);
            $this->assertStringContainsString('@ 0.40', $entry->reason);
            $this->assertStringContainsString('looks stale', $entry->reason);
        }

        // Full faithful rebuild: every page survives.
        $this->assertCount(7, $plan->nav);
        $this->assertCount(18, $plan->kept_pages);
    }

    #[Test]
    public function high_confidence_drop_becomes_park_reversible_in_v1(): void
    {
        // v1 never deletes. A high-confidence drop is rewritten to park —
        // the page is absent from nav/kept_pages but the ledger entry keeps
        // the decision recoverable.
        $manifest = RealManifests::stthomas();
        $agent = new FakeClassifierAgent;
        $agent->respondWith(static fn (InventoryPage $p): ClassificationResponse => new ClassificationResponse(
            action: DecisionAction::Drop,
            confidence: 0.95,
            reason: 'definitely stale',
        ));

        $plan = (new RootNavPlanner($agent))->plan($manifest);

        $drops = array_values(array_filter(
            $plan->ledger->entries->items(),
            static fn (DecisionEntry $e) => $e->action === DecisionAction::Drop,
        ));
        $this->assertCount(0, $drops, 'v1 should never emit a Drop — all become Park');

        $parks = array_values(array_filter(
            $plan->ledger->entries->items(),
            static fn (DecisionEntry $e) => $e->action === DecisionAction::Park,
        ));
        $this->assertCount(16, $parks);
        foreach ($parks as $entry) {
            $this->assertStringContainsString('high-confidence drop parked', $entry->reason);
            $this->assertStringContainsString('v1 never deletes', $entry->reason);
            $this->assertStringContainsString('definitely stale', $entry->reason);
        }

        // Parks don't reach nav/kept_pages — only the two deterministic
        // external keeps (toolsLink Dibs + LinkNode Swag/Spirit Wear) survive.
        $this->assertCount(1, $plan->nav);
        $this->assertCount(2, $plan->kept_pages);
    }

    #[Test]
    public function high_confidence_park_passes_through_unchanged(): void
    {
        // The threshold is for setting pages aside as junk. A confident PARK
        // from the model — placeholder, "coming soon", empty stub — passes
        // through with the model's own reason intact, no rewriting.
        $manifest = RealManifests::stthomas();
        $agent = new FakeClassifierAgent;
        $agent->respondWith(static fn (InventoryPage $p): ClassificationResponse => new ClassificationResponse(
            action: DecisionAction::Park,
            confidence: 0.85,                // strictly > 0.80 threshold
            reason: 'placeholder page (Coming Soon)',
        ));

        $plan = (new RootNavPlanner($agent))->plan($manifest);

        $parks = array_values(array_filter(
            $plan->ledger->entries->items(),
            static fn (DecisionEntry $e) => $e->action === DecisionAction::Park,
        ));
        $this->assertCount(16, $parks);
        foreach ($parks as $entry) {
            $this->assertSame('placeholder page (Coming Soon)', $entry->reason, 'model reason must pass through');
            $this->assertSame(0.85, $entry->confidence);
        }
    }

    #[Test]
    public function exactly_080_park_or_drop_falls_to_keep_strict_threshold(): void
    {
        // Strict > 0.80 boundary: a model verdict at exactly 0.80 confidence
        // is NOT enough to set the page aside. The page is kept, the model's
        // verdict survives in the reason. Verified for both park and drop.
        foreach ([DecisionAction::Park, DecisionAction::Drop] as $modelAction) {
            $manifest = RealManifests::stthomas();
            $agent = new FakeClassifierAgent;
            $agent->respondWith(static fn (InventoryPage $p): ClassificationResponse => new ClassificationResponse(
                action: $modelAction,
                confidence: 0.80,
                reason: 'borderline value',
            ));

            $plan = (new RootNavPlanner($agent))->plan($manifest);

            $parks = array_values(array_filter(
                $plan->ledger->entries->items(),
                static fn (DecisionEntry $e) => $e->action === DecisionAction::Park,
            ));
            $drops = array_values(array_filter(
                $plan->ledger->entries->items(),
                static fn (DecisionEntry $e) => $e->action === DecisionAction::Drop,
            ));
            $this->assertCount(0, $parks, "exactly-0.80 {$modelAction->value} must NOT honor the model");
            $this->assertCount(0, $drops);

            $keepsWithRecallReason = array_values(array_filter(
                $plan->ledger->entries->items(),
                static fn (DecisionEntry $e) => str_contains($e->reason, 'recall-biased keep'),
            ));
            $this->assertCount(16, $keepsWithRecallReason);
            foreach ($keepsWithRecallReason as $entry) {
                $this->assertSame(DecisionAction::Keep, $entry->action);
                $this->assertStringContainsString("model wanted {$modelAction->value}", $entry->reason);
                $this->assertStringContainsString('@ 0.80', $entry->reason);
                $this->assertStringContainsString('borderline value', $entry->reason);
            }

            // Faithful rebuild: every page survives.
            $this->assertCount(18, $plan->kept_pages);
        }
    }

    #[Test]
    public function model_merge_is_suggestion_only_page_kept_with_target_in_reason(): void
    {
        // v1 never auto-folds. A model MERGE is rewritten as a KEEP; the
        // merge target lands in the ledger entry's reason. The DecisionEntry
        // itself carries action=Keep and merged_into=null — the suggestion
        // lives in the reason string for human review.
        $manifest = RealManifests::stthomas();
        $agent = new FakeClassifierAgent;
        $agent->respondWith(static fn (InventoryPage $p): ClassificationResponse => new ClassificationResponse(
            action: DecisionAction::Merge,
            confidence: 0.90,
            reason: 'closely related to Programs',
            merged_into: 'https://www.stthomassoccer.com/page/show/3060737-programs',
        ));

        $plan = (new RootNavPlanner($agent))->plan($manifest);

        // v1 should never emit Merge — all become Keep.
        $merges = array_values(array_filter(
            $plan->ledger->entries->items(),
            static fn (DecisionEntry $e) => $e->action === DecisionAction::Merge,
        ));
        $this->assertCount(0, $merges, 'v1 never emits MERGE — engine does not auto-fold pages');

        $kept = array_values(array_filter(
            $plan->ledger->entries->items(),
            static fn (DecisionEntry $e) => str_contains($e->reason, 'model suggested merge'),
        ));
        $this->assertCount(16, $kept);
        foreach ($kept as $entry) {
            $this->assertSame(DecisionAction::Keep, $entry->action);
            $this->assertStringContainsString('into https://www.stthomassoccer.com/page/show/3060737-programs', $entry->reason);
            $this->assertStringContainsString('closely related to Programs', $entry->reason);
            $this->assertNull($entry->merged_into, 'merged_into lives in the reason, not on the entry');
        }

        // Faithful rebuild — every page survives.
        $this->assertCount(7, $plan->nav);
        $this->assertCount(18, $plan->kept_pages);
    }

    /**
     * @param  array<int, DecisionEntry>  $entries
     */
    private function ledgerEntryFor(array $entries, string $target): ?DecisionEntry
    {
        foreach ($entries as $e) {
            if ($e->target === $target) {
                return $e;
            }
        }

        return null;
    }

    /**
     * @param  array<int, DecisionEntry>  $entries
     */
    private function ledgerEntryByReasonFragment(array $entries, string $fragment): ?DecisionEntry
    {
        foreach ($entries as $e) {
            if (str_contains($e->reason, $fragment)) {
                return $e;
            }
        }

        return null;
    }
}
