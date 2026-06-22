<?php

declare(strict_types=1);

namespace App\Services\Plan;

use App\Data\ClassificationResponse;
use App\Data\DecisionAction;
use App\Data\DecisionEntry;
use App\Data\DecisionLedger;
use App\Data\InventoryPage;
use App\Data\Manifest;
use App\Data\NavItem;
use App\Data\NavNode;
use App\Data\PageInventory;
use App\Data\SitePlan;
use Illuminate\Support\Str;
use Spatie\LaravelData\DataCollection;

// Default Planner implementation. v1 is a FAITHFUL-REBUILD migration: the
// engine rebuilds the whole site by default and only sets aside pages it's
// very confident are junk. Rules enforced in code:
//
//   - DROP never deletes anything in v1. A high-confidence drop is treated
//     as PARK (reversible). A drop or park AT OR BELOW the recall threshold
//     is reclassified as KEEP, with the model's original verdict preserved
//     in the ledger entry so a reviewer can still see why it was flagged.
//   - PARK is reserved for genuinely-obvious low-value pages (empty stubs,
//     placeholders, "coming soon") — meaning the model's park/drop
//     confidence is STRICTLY GREATER THAN LOW_VALUE_CONFIDENCE_THRESHOLD.
//   - MERGE is a suggestion, not an action. v1 never auto-folds pages
//     together. A model MERGE is rewritten as KEEP; the suggestion and its
//     merged_into target are preserved in the ledger entry's reason so a
//     human reviewer sees it.
//   - Every page emits a DecisionLedger entry — including external + dynamic.
//   - External/dynamic dispositions are deterministic; ONLY kind=page goes
//     to the LLM.
final class RootNavPlanner implements Planner
{
    private const BATCH_SIZE = 20;

    // v1 recall bias: only treat the model's park/drop as authoritative when
    // its confidence is STRICTLY GREATER THAN this. At or below this the
    // page is kept; the model's verdict survives in the ledger entry's
    // reason so a human reviewer can still act on it.
    private const LOW_VALUE_CONFIDENCE_THRESHOLD = 0.80;

    public function __construct(
        private readonly ClassifierAgent $classifier,
    ) {}

    public function plan(Manifest $manifest): SitePlan
    {
        $inventory = $this->inventory($manifest);
        $entries = $this->classify($inventory, $manifest->brand->voice_hint ?? '');

        return $this->decideIa($inventory, $entries);
    }

    private function inventory(Manifest $manifest): PageInventory
    {
        /** @var array<int, InventoryPage> $out */
        $out = [];
        /** @var array<int, NavNode> $roots */
        $roots = $manifest->structure->nav->items();
        $this->walkInventory($roots, [], 0, $out);

        return new PageInventory(
            pages: new DataCollection(InventoryPage::class, $out),
        );
    }

    /**
     * @param  array<int, NavNode>  $nodes
     * @param  array<int, string>  $navPath
     * @param  array<int, InventoryPage>  $out
     */
    private function walkInventory(array $nodes, array $navPath, int $depth, array &$out): void
    {
        foreach ($nodes as $node) {
            $out[] = new InventoryPage(
                label: $node->label,
                url: $node->url,
                kind: $node->kind,
                node_type: $node->node_type,
                page_node_id: $node->page_node_id,
                external_subtype: $node->external_subtype,
                depth: $depth,
                nav_path: $navPath,
            );
            $childPath = [...$navPath, $node->label];
            /** @var array<int, NavNode> $children */
            $children = $node->children->items();
            $this->walkInventory($children, $childPath, $depth + 1, $out);
        }
    }

    /**
     * @return array<int, DecisionEntry>
     */
    private function classify(PageInventory $inventory, string $brandVoiceHint): array
    {
        /** @var array<int, DecisionEntry> $entries */
        $entries = [];
        /** @var array<int, InventoryPage> $needsLlm */
        $needsLlm = [];

        /** @var array<int, InventoryPage> $pages */
        $pages = $inventory->pages->items();
        foreach ($pages as $page) {
            $deterministic = $this->deterministicAction($page);
            if ($deterministic !== null) {
                $entries[] = $deterministic;

                continue;
            }
            // kind === 'page' — let the LLM decide.
            $needsLlm[] = $page;
        }

        // Batched LLM classification per BUILD.md (~20 pages / Haiku call).
        foreach (array_chunk($needsLlm, self::BATCH_SIZE) as $batch) {
            $responses = $this->classifier->classifyBatch($batch, $brandVoiceHint);
            foreach ($batch as $i => $page) {
                $resp = $responses[$i] ?? null;
                if (! $resp instanceof ClassificationResponse) {
                    // Defensive: if the agent shorted the batch, park the page
                    // rather than silently drop it.
                    $entries[] = new DecisionEntry(
                        target: $this->targetOf($page),
                        action: DecisionAction::Park,
                        reason: 'classifier returned no response; parked for review',
                        confidence: 0.0,
                    );

                    continue;
                }
                $entries[] = $this->applyRecallBias($page, $resp);
            }
        }

        return $entries;
    }

    /**
     * Deterministic dispositions for non-Page kinds. Returns null when the
     * page must be classified by the LLM (kind === 'page').
     */
    private function deterministicAction(InventoryPage $page): ?DecisionEntry
    {
        if ($page->kind === 'external') {
            $sub = $page->external_subtype ?? 'unknown';
            $reason = match ($page->external_subtype) {
                'external_link' => 'LinkNode external link preserved',
                'se_tool' => 'SE third-party tool link preserved',
                default => "external sibling preserved ({$sub})",
            };

            return new DecisionEntry(
                target: $this->targetOf($page),
                action: DecisionAction::Keep,
                reason: $reason,
                confidence: 1.0,
            );
        }
        if (str_starts_with($page->kind, 'dynamic_')) {
            $rawType = $page->node_type ?? 'unknown';

            return new DecisionEntry(
                target: $this->targetOf($page),
                action: DecisionAction::Dynamic,
                reason: "dynamic SE feature ({$rawType})",
                confidence: 1.0,
            );
        }
        if ($page->kind === 'unknown') {
            // Conservative: never silently drop an unrecognised node.
            return new DecisionEntry(
                target: $this->targetOf($page),
                action: DecisionAction::Park,
                reason: 'unknown node shape; parked for human review',
                confidence: 0.5,
            );
        }

        return null; // kind === 'page' → LLM
    }

    private function applyRecallBias(InventoryPage $page, ClassificationResponse $resp): DecisionEntry
    {
        $action = $resp->action;
        $reason = $resp->reason;
        $mergedInto = $resp->merged_into;

        if ($action === DecisionAction::Park || $action === DecisionAction::Drop) {
            if ($resp->confidence <= self::LOW_VALUE_CONFIDENCE_THRESHOLD) {
                // Strict > 0.80 to set aside: at-or-below-threshold flags are
                // not enough. Keep the page; preserve the model's reasoning
                // so a reviewer can still see why it was flagged.
                $action = DecisionAction::Keep;
                $reason = sprintf(
                    'recall-biased keep (model wanted %s @ %.2f: %s)',
                    $resp->action->value,
                    $resp->confidence,
                    $resp->reason,
                );
            } elseif ($resp->action === DecisionAction::Drop) {
                // High-confidence drop is still reversible in v1: PARK, not delete.
                $action = DecisionAction::Park;
                $reason = sprintf(
                    'high-confidence drop parked (v1 never deletes; model: %s)',
                    $resp->reason,
                );
            }
            // High-confidence PARK passes through unchanged.
        } elseif ($action === DecisionAction::Merge) {
            // v1 is a faithful rebuild — the engine never folds pages
            // together automatically. Treat a model MERGE as a suggestion:
            // the page stays in nav + kept_pages; the merge intent and its
            // target (if any) land in the ledger entry's reason for a
            // human reviewer to act on.
            $mergedIntoSuffix = is_string($mergedInto) && $mergedInto !== ''
                ? ' into '.$mergedInto
                : '';
            $action = DecisionAction::Keep;
            $reason = sprintf(
                'kept (v1 ignores merge suggestions; model suggested merge%s @ %.2f: %s)',
                $mergedIntoSuffix,
                $resp->confidence,
                $resp->reason,
            );
            $mergedInto = null;
        }

        return new DecisionEntry(
            target: $this->targetOf($page),
            action: $action,
            reason: $reason,
            confidence: $resp->confidence,
            merged_into: $mergedInto,
        );
    }

    /**
     * @param  array<int, DecisionEntry>  $entries
     */
    private function decideIa(PageInventory $inventory, array $entries): SitePlan
    {
        $byTarget = [];
        foreach ($entries as $entry) {
            $byTarget[$entry->target] = $entry;
        }

        /** @var array<int, InventoryPage> $keptPages */
        $keptPages = [];
        /** @var array<int, NavItem> $navItems */
        $navItems = [];
        $order = 0;

        /** @var array<int, InventoryPage> $pages */
        $pages = $inventory->pages->items();
        foreach ($pages as $page) {
            $entry = $byTarget[$this->targetOf($page)] ?? null;
            if ($entry === null) {
                continue;
            }

            // Drop / Park: absent from final IA, present in ledger.
            if ($entry->action === DecisionAction::Drop || $entry->action === DecisionAction::Park) {
                continue;
            }

            // Merge: kept in inventory, but the merged_into pointer in the
            // ledger entry tells GENERATE which target page to fold into.
            $keptPages[] = $page;

            // v1 IA: nav = depth-0 surviving siblings, in SE's original order.
            // A later "what's the IA" LLM pass can re-order / regroup.
            if ($page->depth === 0) {
                $navItems[] = new NavItem(
                    label: $page->label,
                    page_slug: $this->slugOf($page),
                    order: $order++,
                );
            }
        }

        return new SitePlan(
            nav: new DataCollection(NavItem::class, $navItems),
            kept_pages: new DataCollection(InventoryPage::class, $keptPages),
            ledger: new DecisionLedger(
                entries: new DataCollection(DecisionEntry::class, $entries),
            ),
        );
    }

    /**
     * Stable identifier for ledger / kept-pages correlation. Prefers URL,
     * falls back to a synthetic `page_node:<id>` or `label:<slug>` so every
     * page has a unique key even when the URL is null (e.g. the toolsLink
     * 'Dibs' sibling).
     */
    private function targetOf(InventoryPage $page): string
    {
        if ($page->url !== null && $page->url !== '') {
            return $page->url;
        }
        if ($page->page_node_id !== null) {
            return 'page_node:'.$page->page_node_id;
        }

        return 'label:'.Str::slug($page->label);
    }

    private function slugOf(InventoryPage $page): string
    {
        return Str::slug($page->label !== '' ? $page->label : 'page');
    }
}
