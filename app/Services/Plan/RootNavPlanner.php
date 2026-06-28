<?php

declare(strict_types=1);

namespace App\Services\Plan;

use App\Data\ClassificationResponse;
use App\Data\ContentRef;
use App\Data\DecisionAction;
use App\Data\DecisionEntry;
use App\Data\DecisionLedger;
use App\Data\InventoryPage;
use App\Data\Manifest;
use App\Data\NavItem;
use App\Data\NavNode;
use App\Data\PageInventory;
use App\Data\PlatformBlockType;
use App\Data\SitePlan;
use App\Services\Generate\ContentLoader;
use App\Services\Generate\PageSlug;
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
        private readonly ContentLoader $contentLoader,
        private readonly SePlatformContentDetector $sePlatformDetector,
    ) {}

    public function plan(Manifest $manifest): SitePlan
    {
        $inventory = $this->inventory($manifest);
        $entries = $this->classify($inventory, $manifest);

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
                has_children: $node->children->count() > 0,
            );
            $childPath = [...$navPath, $node->label];
            /** @var array<int, NavNode> $children */
            $children = $node->children->items();
            $this->walkInventory($children, $childPath, $depth + 1, $out);
        }
    }

    /**
     * Classify the inventory in four phases:
     *   1. Deterministic pass — also tracks platform_dynamic ancestors so any
     *      descendant of a deterministic platform_dynamic is marked Subsumed
     *      BEFORE phase 2, never sent to the LLM.
     *   1.5. Body-content SE-platform park — for tentatively-Keep kind=page
     *      that has a captured ContentRef, load the body and run the
     *      three-signal detector. Pages that match are parked as
     *      se_platform_content BEFORE the LLM phase so we never burn a
     *      classify call on SE-templated tutorial content. Pages without a
     *      ContentRef bypass the detector and continue to phase 2 — can't
     *      body-detect what we don't have.
     *   2. LLM batches for the remaining ambiguous (kind=page, non-subsumed,
     *      non-SE-platform) pages.
     *   3. Retroactive subsumption — if phase 2 produced a platform_dynamic
     *      from the LLM, override its descendants (already LLM-classified
     *      independently) to Subsumed so the final ledger respects the rule
     *      that the parent block represents the whole subtree.
     *
     * @return array<int, DecisionEntry>
     */
    private function classify(PageInventory $inventory, Manifest $manifest): array
    {
        $brandVoiceHint = $manifest->brand->voice_hint ?? '';
        $contentByAbsoluteUrl = $this->indexContentRefs($manifest);
        /** @var array<int, DecisionEntry|null> $entries  indexed parallel to $pages */
        $entries = [];
        /** @var array<int, array{index: int, page: InventoryPage}> $needsLlm */
        $needsLlm = [];

        /** @var array<int, array{depth: int, label: string, block: PlatformBlockType}> $ancestors */
        $ancestors = [];

        /** @var array<int, InventoryPage> $pages */
        $pages = $inventory->pages->items();

        // Phase 1: deterministic + early subsumption.
        foreach ($pages as $i => $page) {
            $this->popStaleAncestors($ancestors, $page->depth);

            if ($ancestors !== []) {
                $top = $ancestors[array_key_last($ancestors)];
                $entries[$i] = $this->subsumedEntry($page, $top['block'], $top['label']);

                continue;
            }

            $deterministic = $this->deterministicAction($page);
            if ($deterministic !== null) {
                $entries[$i] = $deterministic;
                if ($deterministic->action === DecisionAction::PlatformDynamic && $deterministic->platform_block_type !== null) {
                    $ancestors[] = [
                        'depth' => $page->depth,
                        'label' => $page->label,
                        'block' => $deterministic->platform_block_type,
                    ];
                }

                continue;
            }

            $entries[$i] = null;
            $needsLlm[] = ['index' => $i, 'page' => $page];
        }

        // Phase 1.5: body-content SE-platform-content park. Runs ONLY on
        // pages currently null (tentatively-Keep), kind=page, that have a
        // captured ContentRef. The detector is conservative-by-design (see
        // SePlatformContentDetector docs): three reinforcing signals all
        // required, vocabulary signal is the load-bearing false-positive
        // guard. Pages without a ContentRef silently fall through to the
        // LLM phase below — can't body-detect what we don't have.
        //
        // Direction of risk reverses PLAN's usual recall bias: this is a
        // PARK (removal) action, so false-park is destructive. The
        // detector's bar is "overwhelmingly SE-templated content";
        // borderline stays Keep and goes to the LLM.
        /** @var array<int, array{index: int, page: InventoryPage}> $stillNeedsLlm */
        $stillNeedsLlm = [];
        foreach ($needsLlm as $item) {
            $page = $item['page'];
            if ($page->kind !== 'page' || $page->url === null || $page->url === '') {
                $stillNeedsLlm[] = $item;

                continue;
            }
            $absoluteUrl = $this->absoluteUrl($manifest->source_url, $page->url);
            $contentRef = $contentByAbsoluteUrl[$absoluteUrl] ?? null;
            if ($contentRef === null) {
                $stillNeedsLlm[] = $item;

                continue;
            }
            $loaded = $this->contentLoader->load($contentRef);
            if ($loaded === null) {
                $stillNeedsLlm[] = $item;

                continue;
            }
            $verdict = $this->sePlatformDetector->detect($loaded->markdown);
            if (! $verdict->is_se_platform) {
                $stillNeedsLlm[] = $item;

                continue;
            }

            // Loud, specific ledger reason — a reviewer must be able to
            // read EXACTLY why this parked and promote it back if wrong.
            $vocab = implode(', ', $verdict->vocab_phrases_matched);
            $reason = sprintf(
                'se_platform_content (%d SE-tutorial links of %d total, ratio %.2f, vocab phrases: [%s])',
                $verdict->se_platform_links,
                $verdict->total_outbound_links,
                $verdict->ratio,
                $vocab,
            );
            $entries[$item['index']] = new DecisionEntry(
                target: $this->targetOf($page),
                action: DecisionAction::Park,
                reason: $reason,
                confidence: 0.95,
            );
        }
        $needsLlm = $stillNeedsLlm;

        // Phase 2: LLM batches for ambiguous content pages.
        foreach (array_chunk($needsLlm, self::BATCH_SIZE) as $batch) {
            $batchPages = array_map(static fn (array $item): InventoryPage => $item['page'], $batch);
            $responses = $this->classifier->classifyBatch($batchPages, $brandVoiceHint);
            foreach ($batch as $j => $item) {
                $resp = $responses[$j] ?? null;
                $entries[$item['index']] = $resp instanceof ClassificationResponse
                    ? $this->applyRecallBias($item['page'], $resp)
                    : new DecisionEntry(
                        target: $this->targetOf($item['page']),
                        action: DecisionAction::Park,
                        reason: 'classifier returned no response; parked for review',
                        confidence: 0.0,
                    );
            }
        }

        // Phase 3: retroactive subsumption. The LLM may have returned
        // platform_dynamic for a node whose descendants were sent in the
        // same batch; override those descendants to Subsumed now.
        /** @var array<int, array{depth: int, label: string, block: PlatformBlockType}> $stack */
        $stack = [];
        foreach ($pages as $i => $page) {
            $this->popStaleAncestors($stack, $page->depth);

            if ($stack !== []) {
                $top = $stack[array_key_last($stack)];
                $current = $entries[$i] ?? null;
                if ($current === null || $current->action !== DecisionAction::Subsumed) {
                    $entries[$i] = $this->subsumedEntry($page, $top['block'], $top['label']);
                }

                continue;
            }

            $entry = $entries[$i] ?? null;
            if ($entry !== null
                && $entry->action === DecisionAction::PlatformDynamic
                && $entry->platform_block_type !== null) {
                $stack[] = [
                    'depth' => $page->depth,
                    'label' => $page->label,
                    'block' => $entry->platform_block_type,
                ];
            }
        }

        /** @var array<int, DecisionEntry> $result */
        $result = [];
        foreach ($entries as $entry) {
            if ($entry !== null) {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array{depth: int, label: string, block: PlatformBlockType}>  $stack
     */
    private function popStaleAncestors(array &$stack, int $currentDepth): void
    {
        while ($stack !== []) {
            $top = $stack[array_key_last($stack)];
            if ($top['depth'] < $currentDepth) {
                return;
            }
            array_pop($stack);
        }
    }

    /**
     * Build a url → ContentRef map keyed by the ABSOLUTE URL the extractor
     * stored on each ContentRef. Phase 1.5 normalises InventoryPage.url
     * (relative) to absolute before lookup so the join lines up.
     *
     * @return array<string, ContentRef>
     */
    private function indexContentRefs(Manifest $manifest): array
    {
        /** @var array<string, ContentRef> $out */
        $out = [];
        /** @var array<int, ContentRef> $items */
        $items = $manifest->content_refs->items();
        foreach ($items as $ref) {
            $out[$ref->url] = $ref;
        }

        return $out;
    }

    private function absoluteUrl(string $orgUrl, string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim($orgUrl, '/').'/'.ltrim($url, '/');
    }

    private function subsumedEntry(InventoryPage $page, PlatformBlockType $block, string $parentLabel): DecisionEntry
    {
        return new DecisionEntry(
            target: $this->targetOf($page),
            action: DecisionAction::Subsumed,
            reason: "subsumed by parent {$block->value} block at '{$parentLabel}'",
            confidence: 1.0,
        );
    }

    /**
     * Deterministic dispositions. Order matters:
     *  1. Registration intent — preserved as a nav entry with a retarget note.
     *  2. SE platform / tool / help link — parked (removed in rebuild).
     *  3. kind === 'external' (non-SE) — kept as a link.
     *  4. kind starts with 'dynamic_' — preserved as a dynamic SE feature.
     *  5. kind === 'unknown' — parked for human review.
     *  6. kind === 'page' name-map → platform_dynamic.
     *  7. Else → null (page goes to the LLM).
     *
     * Returns null when the page must be classified by the LLM.
     */
    private function deterministicAction(InventoryPage $page): ?DecisionEntry
    {
        // 1. Registration intent: any node whose label/url indicates
        //    registration is kept as a nav entry; GENERATE retargets the
        //    link to TeamLinkt's secure registration URL.
        if ($this->isRegistrationLink($page)) {
            return new DecisionEntry(
                target: $this->targetOf($page),
                action: DecisionAction::Keep,
                reason: 'registration link — GENERATE should retarget to TeamLinkt secure registration URL',
                confidence: 1.0,
            );
        }

        // 2. SE PLATFORM / tool / help link — removed in the rebuild.
        //    NB: this is for SE platform PAGES (Dibs, /sportsengine, SE login),
        //    NOT for sportngin.com CDN asset URLs (banner / logo / content
        //    images). Those are content references the brand extractor and
        //    GENERATE deal with — see isSePlatformLink() for the precise
        //    set of matched signals.
        if ($this->isSePlatformLink($page)) {
            return new DecisionEntry(
                target: $this->targetOf($page),
                action: DecisionAction::Park,
                reason: 'SE platform link, removed in TeamLinkt rebuild',
                confidence: 1.0,
            );
        }

        // 3. External — non-SE, non-registration. Kept as a link.
        if ($page->kind === 'external') {
            $sub = $page->external_subtype ?? 'unknown';
            $reason = match ($page->external_subtype) {
                'external_link' => 'LinkNode external link preserved',
                default => "external sibling preserved ({$sub})",
            };

            return new DecisionEntry(
                target: $this->targetOf($page),
                action: DecisionAction::Keep,
                reason: $reason,
                confidence: 1.0,
            );
        }

        // 4. Dynamic SE features routed by node_type. Calendar and NewsNode
        //    become TeamLinkt platform blocks (Calendar / News) — same
        //    "reproduced as our own block, zero live SE dependency" rule that
        //    applies to Teams / Standings etc. dynamic_other is a vestigial
        //    fallback for unrecognized SE dynamic types.
        if (str_starts_with($page->kind, 'dynamic_')) {
            $rawType = $page->node_type ?? 'unknown';

            $blockType = match ($page->kind) {
                'dynamic_calendar' => PlatformBlockType::Calendar,
                'dynamic_news' => PlatformBlockType::News,
                default => null,
            };

            if ($blockType !== null) {
                return new DecisionEntry(
                    target: $this->targetOf($page),
                    action: DecisionAction::PlatformDynamic,
                    reason: "rebuilt by TeamLinkt {$blockType->value} block (node_type={$rawType})",
                    confidence: 1.0,
                    platform_block_type: $blockType,
                );
            }

            // TODO: add a PlatformBlockType for any new SE dynamic type
            // observed in the wild; v1 should never emit `Dynamic` in
            // practice — every SE dynamic feature ought to map to a block.
            return new DecisionEntry(
                target: $this->targetOf($page),
                action: DecisionAction::Dynamic,
                reason: "dynamic SE feature ({$rawType}) — no platform block mapping yet",
                confidence: 1.0,
            );
        }

        // 5. Unknown — never silently drop, park for review.
        if ($page->kind === 'unknown') {
            return new DecisionEntry(
                target: $this->targetOf($page),
                action: DecisionAction::Park,
                reason: 'unknown node shape; parked for human review',
                confidence: 0.5,
            );
        }

        // 6. Page name-map → platform_dynamic for unambiguous data labels.
        //    A false 'platform_dynamic' replaces real content with an empty
        //    block (destructive), so this only matches names that almost
        //    certainly ARE live-data listings.
        if ($page->kind === 'page') {
            $blockType = $this->matchPlatformBlockByName($page);
            if ($blockType !== null) {
                return new DecisionEntry(
                    target: $this->targetOf($page),
                    action: DecisionAction::PlatformDynamic,
                    reason: "rebuilt by TeamLinkt {$blockType->value} block (name-matched: '{$page->label}')",
                    confidence: 1.0,
                    platform_block_type: $blockType,
                );
            }
        }

        return null; // kind === 'page' that didn't name-match → LLM
    }

    /**
     * "register" / "registration" as a whole word in the label, OR as a
     * path segment in the URL. Tight matching to avoid sweeping in
     * unrelated terms.
     */
    private function isRegistrationLink(InventoryPage $page): bool
    {
        $label = strtolower(trim($page->label));
        if (preg_match('/\b(register|registration)\b/', $label) === 1) {
            return true;
        }
        if ($page->url !== null && preg_match('~(^|/)(register|registration)(/|$|\?|\#)~i', $page->url) === 1) {
            return true;
        }

        return false;
    }

    /**
     * A SportsEngine PLATFORM / TOOL / HELP nav link — the toolsLink/Dibs
     * sibling, anything labelled "sportsengine"/"sports engine", or a URL
     * pointing at a known SE platform path. CRITICAL: only matches nav-link
     * intent. Does NOT match generic `cdn*.sportngin.com` / `assets.ngin.com`
     * asset URLs (those are brand + content assets that GENERATE re-hosts;
     * see CLAUDE.md "SE platform links vs SE CDN assets").
     */
    private function isSePlatformLink(InventoryPage $page): bool
    {
        if ($page->external_subtype === 'se_tool') {
            return true;
        }

        $label = strtolower(trim($page->label));
        if (preg_match('/sports\s*engine/', $label) === 1) {
            return true;
        }

        if ($page->url !== null) {
            $path = parse_url($page->url, PHP_URL_PATH);
            $path = is_string($path) ? strtolower($path) : '';

            // Specific SE platform routes — exact match for /sportsengine,
            // prefix match for the SE-managed sub-areas. Anything outside
            // these paths is NOT classified as SE platform here.
            if ($path === '/sportsengine' || str_starts_with($path, '/sportsengine/')) {
                return true;
            }
            if (str_starts_with($path, '/dib_sessions')) {
                return true;
            }
            if (in_array($path, ['/sn_signin', '/sn_login', '/se_login', '/se_signin'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deterministic page-name → PlatformBlockType map. ONLY unambiguous
     * data-listing labels are honored; ambiguous words (tryouts, recruiting,
     * programs, events, camps) are intentionally NOT in this map — they go
     * to the LLM. 'Teams' requires the node to have children (a Teams
     * directory is a block; a leaf "Teams" page is content).
     */
    private function matchPlatformBlockByName(InventoryPage $page): ?PlatformBlockType
    {
        $label = strtolower(trim($page->label));

        return match (true) {
            $label === 'standings' => PlatformBlockType::Standings,
            in_array($label, ['score', 'scores', 'result', 'results'], true) => PlatformBlockType::Scores,
            in_array($label, ['schedule', 'schedules'], true) => PlatformBlockType::Schedule,
            in_array($label, ['roster', 'rosters'], true) => PlatformBlockType::Roster,
            in_array($label, ['division', 'divisions'], true) => PlatformBlockType::Divisions,
            $label === 'teams' && $page->has_children => PlatformBlockType::Teams,
            // Contacts intentionally absent — too often a content "Contact Us"
            // page, not a live-data directory. Let the LLM call this.
            default => null,
        };
    }

    private function applyRecallBias(InventoryPage $page, ClassificationResponse $resp): DecisionEntry
    {
        $target = $this->targetOf($page);

        // Park / Drop — recall threshold applies; high-confidence drop becomes park.
        if ($resp->action === DecisionAction::Park || $resp->action === DecisionAction::Drop) {
            if ($resp->confidence <= self::LOW_VALUE_CONFIDENCE_THRESHOLD) {
                return new DecisionEntry(
                    target: $target,
                    action: DecisionAction::Keep,
                    reason: sprintf(
                        'recall-biased keep (model wanted %s @ %.2f: %s)',
                        $resp->action->value,
                        $resp->confidence,
                        $resp->reason,
                    ),
                    confidence: $resp->confidence,
                );
            }
            if ($resp->action === DecisionAction::Drop) {
                return new DecisionEntry(
                    target: $target,
                    action: DecisionAction::Park,
                    reason: sprintf('high-confidence drop parked (v1 never deletes; model: %s)', $resp->reason),
                    confidence: $resp->confidence,
                );
            }

            // High-confidence Park — passthrough with the model's own reason.
            return new DecisionEntry(
                target: $target,
                action: DecisionAction::Park,
                reason: $resp->reason,
                confidence: $resp->confidence,
            );
        }

        // Merge — never executed in v1; rewrite as keep with the merge
        // target preserved in the reason for a human reviewer.
        if ($resp->action === DecisionAction::Merge) {
            $intoSuffix = is_string($resp->merged_into) && $resp->merged_into !== ''
                ? ' into '.$resp->merged_into
                : '';

            return new DecisionEntry(
                target: $target,
                action: DecisionAction::Keep,
                reason: sprintf(
                    'kept (v1 ignores merge suggestions; model suggested merge%s @ %.2f: %s)',
                    $intoSuffix,
                    $resp->confidence,
                    $resp->reason,
                ),
                confidence: $resp->confidence,
            );
        }

        // Platform-dynamic from the LLM. A false positive here REPLACES real
        // content with an empty block (destructive), so require strict
        // > 0.80 + a resolved block type. Otherwise fall back to keep.
        if ($resp->action === DecisionAction::PlatformDynamic) {
            if ($resp->confidence <= self::LOW_VALUE_CONFIDENCE_THRESHOLD || $resp->platform_block_type === null) {
                $hint = $resp->platform_block_type !== null
                    ? '/'.$resp->platform_block_type->value
                    : ' (no block type)';

                return new DecisionEntry(
                    target: $target,
                    action: DecisionAction::Keep,
                    reason: sprintf(
                        'recall-biased keep (model wanted platform_dynamic%s @ %.2f: %s)',
                        $hint,
                        $resp->confidence,
                        $resp->reason,
                    ),
                    confidence: $resp->confidence,
                );
            }

            return new DecisionEntry(
                target: $target,
                action: DecisionAction::PlatformDynamic,
                reason: sprintf(
                    'rebuilt by TeamLinkt %s block (LLM-classified @ %.2f): %s',
                    $resp->platform_block_type->value,
                    $resp->confidence,
                    $resp->reason,
                ),
                confidence: $resp->confidence,
                platform_block_type: $resp->platform_block_type,
            );
        }

        // Default: Keep (or any unforeseen action) — passthrough.
        return new DecisionEntry(
            target: $target,
            action: DecisionAction::Keep,
            reason: $resp->reason,
            confidence: $resp->confidence,
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

            // Drop / Park / Subsumed: absent from final IA, present in ledger.
            // Subsumed pages are represented by their ancestor platform_dynamic
            // block — keeping them as standalone entries would double-count.
            if ($entry->action === DecisionAction::Drop
                || $entry->action === DecisionAction::Park
                || $entry->action === DecisionAction::Subsumed) {
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

    // Single source of truth: defer to PageSlug::of() so NavItem.page_slug
    // matches the slug IR-pass, block-fill, the assembler, and the platform-
    // block renderer all use. A fork here would break nav references on
    // the rebuilt site — every consumer that looks up a page by slug
    // would silently miss-match. See PageSlug docblock.
    private function slugOf(InventoryPage $page): string
    {
        return PageSlug::of($page);
    }
}
