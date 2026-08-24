<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\AssemblyFailure;
use App\Data\AssemblyResult;
use App\Data\AssemblyStatus;
use App\Data\ConversionFailure;
use App\Data\ConversionResult;
use App\Data\ConversionStage;
use App\Data\ConversionStatus;
use App\Data\DecisionAction;
use App\Data\InventoryPage;
use App\Data\Manifest;
use App\Data\NavItem;
use App\Data\PlatformRenderFailure;
use App\Data\PlatformRenderResult;
use App\Data\PlatformRenderStatus;
use App\Data\PuckOutput;
use App\Data\ResolvedNavItem;
use App\Data\ResolvedNavStatus;
use App\Data\SiteImport\Diagnostic;
use App\Data\SitePlan;
use App\Services\Product\ProductClient;
use Spatie\LaravelData\DataCollection;
use Throwable;

// GENERATE stage 3 slice 2f — draft-landing. Deterministic — NO LLM.
//
// Folds the two PuckOutput streams (AssemblyResult.pages from content
// pages, PlatformRenderResult.pages from platform_dynamic ledger
// entries) into one page_map keyed by PageSlug::of(). Reconciles
// SitePlan.nav so each NavItem.page_slug joins into page_map keys —
// necessary because the existing tbirdhoops fixture's style_brief.nav
// carries the planner's pre-fix label-derived slugs (e.g. 'home') while
// the page_map keys are page-id slugs (e.g. 'page-7188115'). The
// planner has been fixed to emit page-id slugs going forward (see
// RootNavPlanner::slugOf), but this reconciliation is also done at
// landing time so the existing fixture (the stable replay anchor six
// slices depend on) doesn't need a costly LLM re-capture.
//
// FAITHFUL-REBUILD GUARANTEE chains through here: every expected page
// across the pipeline is in page_map OR in ConversionResult.failures,
// exactly once. Slug collisions between content and platform streams —
// unreachable under current invariants but defensively checked — are
// surfaced as a draft-landing ConversionFailure, not silently
// overwritten.
//
// DRAFT-ONLY GUARANTEE: createDraftSite is only called when status !=
// Failed. When status IS Failed (upstream block-fill abort propagated
// through assembler), page_map is empty and the call is skipped (an
// empty draft would either error on the product side or land a phantom
// empty site — neither is useful). draft_id and draft_url stay null in
// that case; the failure list carries the upstream reasons.
final class DraftLanding
{
    public function __construct(
        private readonly ProductClient $productClient,
    ) {}

    public function run(
        string $conversionId,
        SitePlan $plan,
        AssemblyResult $assembly,
        PlatformRenderResult $platform,
        Manifest $manifest,
    ): ConversionResult {
        [$pageMap, $collisionFailures] = $this->foldPageMap($assembly, $platform);

        /** @var array<int, ConversionFailure> $failures */
        $failures = [];
        foreach ($collisionFailures as $f) {
            $failures[] = $f;
        }
        foreach ($this->liftAssemblyFailures($assembly) as $f) {
            $failures[] = $f;
        }
        foreach ($this->liftPlatformFailures($platform) as $f) {
            $failures[] = $f;
        }

        [$resolvedNav, $navFailures] = $this->reconcileNav($plan, $pageMap);
        foreach ($navFailures as $f) {
            $failures[] = $f;
        }

        $status = $this->resolveStatus($assembly, $platform, $failures);

        $draftId = null;
        $draftUrl = null;
        if ($status !== ConversionStatus::Failed) {
            $response = $this->callCreateDraftSite($manifest, $pageMap);
            if ($response['failure'] !== null) {
                $failures[] = $response['failure'];
                // Network/client error at landing time — the conversion
                // becomes Partial at minimum so the failure surfaces;
                // page_map content is still recorded for re-submission
                // by a human operator.
                if ($status === ConversionStatus::Completed) {
                    $status = ConversionStatus::Partial;
                }
            } else {
                $draftId = $response['draft_id'];
                $draftUrl = $response['draft_url'];
            }
        }

        return new ConversionResult(
            conversion_id: $conversionId,
            org_id: $manifest->org_id,
            source_url: $manifest->source_url,
            page_map: $pageMap,
            nav: new DataCollection(ResolvedNavItem::class, $resolvedNav),
            failures: new DataCollection(ConversionFailure::class, $failures),
            block_issues_by_slug: $assembly->block_issues_by_slug,
            status: $status,
            // Brand + style_brief are passthrough sidecars (NOT in
            // page_map, NOT in createDraftSite's payload). See
            // ConversionResult docblock for the rationale: SCORE & LOG
            // structural-confidence signals + preview chrome.
            brand: $manifest->brand,
            style_brief: $assembly->style_brief,
            // Manifest.asset_refs passthrough — needed by the throwaway
            // preview asset resolver to invert AssetUrlRewriter's
            // source_url → s3_key map. NOT carried into the landed
            // draft (createDraftSite only receives page_map).
            asset_refs: $manifest->asset_refs,
            draft_id: $draftId,
            draft_url: $draftUrl,
            // Sidecar passthrough — deterministic SE-promo/countdown
            // scrubber's audit trail. Empty when the scrubber didn't
            // run (or ran and found nothing to scrub); populated when
            // blocks were removed post-assembly. See ScrubIssue docblock.
            scrub_issues_by_slug: $assembly->scrub_issues_by_slug,
            // Info-severity diagnostics from downstream stages that
            // don't fit ConversionFailure semantics. Union of:
            //   - PlatformBlockRenderer's reserved-route entity-page
            //     skips (contract "Entity detail pages" rule)
            //   - PLAN's paginated-duplicate parks (contract "Paginated
            //     duplicates. Map the first page only") — surfaced from
            //     the SitePlan ledger's Park entries whose reason is
            //     prefixed `paginated_duplicate:`
            // ContractPayloadEmitter surfaces these into
            // envelope.diagnostics[].
            platform_diagnostics: $this->mergePlatformAndPlanDiagnostics($plan, $platform),
        );
    }

    /**
     * Walk the SitePlan ledger for Park entries with the
     * `paginated_duplicate:` reason prefix and emit an info diagnostic
     * per drop. Contract Part II "Pages you should not create" rule.
     * Union with the PlatformRenderResult's own diagnostics.
     *
     * @return DataCollection<int, Diagnostic>
     */
    private function mergePlatformAndPlanDiagnostics(SitePlan $plan, PlatformRenderResult $platform): DataCollection
    {
        /** @var array<int, Diagnostic> $diagnostics */
        $diagnostics = $platform->diagnostics->items();

        foreach ($plan->ledger->entries as $entry) {
            if ($entry->action !== DecisionAction::Park) {
                continue;
            }
            if (! str_starts_with($entry->reason, 'paginated_duplicate:')) {
                continue;
            }
            $diagnostics[] = new Diagnostic(
                severity: 'info',
                code: 'page_dropped_paginated_duplicate',
                message: sprintf(
                    'Page dropped as paginated duplicate: %s. Contract Part II "Pages you should not create": map the first (canonical, un-paginated) page only.',
                    $entry->target,
                ),
            );
        }

        return new DataCollection(Diagnostic::class, $diagnostics);
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: array<int, ConversionFailure>}
     */
    private function foldPageMap(AssemblyResult $assembly, PlatformRenderResult $platform): array
    {
        /** @var array<string, array<string, mixed>> $map */
        $map = [];
        /** @var array<int, ConversionFailure> $collisions */
        $collisions = [];

        /** @var array<int, PuckOutput> $contentPages */
        $contentPages = $assembly->pages->items();
        foreach ($contentPages as $puck) {
            $map[$puck->page_slug] = $this->puckPayload($puck);
        }

        /** @var array<int, PuckOutput> $platformPages */
        $platformPages = $platform->pages->items();
        foreach ($platformPages as $puck) {
            if (isset($map[$puck->page_slug])) {
                // Defensive: streams are disjoint by construction (a page
                // is keep+kind=page XOR platform_dynamic, never both).
                // Unreachable under current invariants; if it fires,
                // PLAN has emitted a malformed ledger.
                $collisions[] = new ConversionFailure(
                    page_slug: $puck->page_slug,
                    page_title: (string) ($puck->root['title'] ?? $puck->page_slug),
                    page_node_id: null,
                    stage: ConversionStage::DraftLanding,
                    reason: "slug collision between content and platform streams: '{$puck->page_slug}' (platform entry dropped to preserve content page)",
                );

                continue;
            }
            $map[$puck->page_slug] = $this->puckPayload($puck);
        }

        return [$map, $collisions];
    }

    /**
     * Strip page_slug from the PuckOutput dict; the slug is the map key,
     * not part of the value payload. Keeps the createDraftSite contract
     * surface clean.
     *
     * @return array<string, mixed>
     */
    private function puckPayload(PuckOutput $puck): array
    {
        return [
            'content' => $puck->content,
            'root' => $puck->root,
            'zones' => $puck->zones,
        ];
    }

    /**
     * @return array<int, ConversionFailure>
     */
    private function liftAssemblyFailures(AssemblyResult $assembly): array
    {
        /** @var array<int, ConversionFailure> $out */
        $out = [];
        /** @var array<int, AssemblyFailure> $items */
        $items = $assembly->failures->items();
        foreach ($items as $f) {
            $out[] = new ConversionFailure(
                page_slug: $f->page_slug,
                page_title: $f->page_title,
                page_node_id: $f->page_node_id,
                stage: $this->inferAssemblyOriginStage($f->reason),
                reason: $f->reason,
            );
        }

        return $out;
    }

    // AssemblyFailure carries chained reasons ('block-fill-failure: …',
    // 'ir-pass-failure: …') from upstream stages. Keep the originating
    // stage in ConversionFailure.stage so SCORE & LOG doesn't have to
    // re-parse reason strings to group failures by stage.
    private function inferAssemblyOriginStage(string $reason): ConversionStage
    {
        if (str_starts_with($reason, 'block-fill-failure: ir-pass-failure:')) {
            return ConversionStage::IrPass;
        }
        if (str_starts_with($reason, 'block-fill-failure:')) {
            return ConversionStage::BlockFill;
        }

        return ConversionStage::Assembler;
    }

    /**
     * @return array<int, ConversionFailure>
     */
    private function liftPlatformFailures(PlatformRenderResult $platform): array
    {
        /** @var array<int, ConversionFailure> $out */
        $out = [];
        /** @var array<int, PlatformRenderFailure> $items */
        $items = $platform->failures->items();
        foreach ($items as $f) {
            $out[] = new ConversionFailure(
                page_slug: $f->page_slug,
                page_title: $f->page_title,
                page_node_id: $f->page_node_id,
                stage: ConversionStage::PlatformRender,
                reason: $f->reason,
            );
        }

        return $out;
    }

    /**
     * Reconcile each NavItem.page_slug to PageSlug::of(matching depth-0
     * InventoryPage), using exact label match as the join key (the
     * planner copies $page->label verbatim into NavItem.label, so this
     * is a clean reverse lookup).
     *
     * @param  array<string, array<string, mixed>>  $pageMap
     * @return array{0: array<int, ResolvedNavItem>, 1: array<int, ConversionFailure>}
     */
    private function reconcileNav(SitePlan $plan, array $pageMap): array
    {
        $depthZeroByLabel = $this->indexDepthZeroByLabel($plan);

        /** @var array<int, ResolvedNavItem> $resolved */
        $resolved = [];
        /** @var array<int, ConversionFailure> $failures */
        $failures = [];

        /** @var array<int, NavItem> $navItems */
        $navItems = $plan->nav->items();
        foreach ($navItems as $nav) {
            $matches = $depthZeroByLabel[$nav->label] ?? [];

            if (count($matches) === 0) {
                // Unreachable under current planner invariants: nav and
                // kept_pages are built in lockstep from the same loop.
                // If it ever fires, planner shape has drifted — log
                // both as nav status + a draft-landing failure.
                $resolved[] = new ResolvedNavItem(
                    label: $nav->label,
                    page_slug: $nav->page_slug,
                    order: $nav->order,
                    status: ResolvedNavStatus::Unresolved,
                    note: 'no depth-0 kept_pages entry with this label',
                );
                $failures[] = new ConversionFailure(
                    page_slug: $nav->page_slug,
                    page_title: $nav->label,
                    page_node_id: null,
                    stage: ConversionStage::DraftLanding,
                    reason: "nav reconciliation failed: NavItem label '{$nav->label}' has no depth-0 kept_pages match",
                );

                continue;
            }

            if (count($matches) > 1) {
                // Two depth-0 kept_pages share a label — upstream data
                // anomaly. Keep the original slug and flag.
                $resolved[] = new ResolvedNavItem(
                    label: $nav->label,
                    page_slug: $nav->page_slug,
                    order: $nav->order,
                    status: ResolvedNavStatus::Unresolved,
                    note: 'ambiguous: '.count($matches).' depth-0 kept_pages share this label',
                );
                $failures[] = new ConversionFailure(
                    page_slug: $nav->page_slug,
                    page_title: $nav->label,
                    page_node_id: null,
                    stage: ConversionStage::DraftLanding,
                    reason: "nav reconciliation ambiguous: NavItem label '{$nav->label}' matches ".count($matches).' depth-0 kept_pages',
                );

                continue;
            }

            $page = $matches[0];
            $resolvedSlug = PageSlug::of($page);

            if (! isset($pageMap[$resolvedSlug])) {
                // External NavItems (kind=external — LinkNode/toolsLink)
                // legitimately have no PuckOutput, so the resolved slug
                // won't be in page_map. That's a nav-layer concern (the
                // rebuilt nav should link out to the external URL), NOT
                // a draft-landing failure. Mark and move on without
                // surfacing a failure.
                $status = $page->kind === 'external'
                    ? ResolvedNavStatus::UnmatchedExternal
                    : ResolvedNavStatus::Unresolved;
                $note = $page->kind === 'external'
                    ? "external link ({$page->external_subtype}) — no page_map entry, nav should link to InventoryPage URL"
                    : 'matched depth-0 page exists but produced no PuckOutput (likely upstream failure)';

                $resolved[] = new ResolvedNavItem(
                    label: $nav->label,
                    page_slug: $resolvedSlug,
                    order: $nav->order,
                    status: $status,
                    note: $note,
                );

                // Only surface as a draft-landing failure when the match
                // is NOT an external — that case means the upstream
                // dropped a page we expected to be in the page_map.
                if ($page->kind !== 'external') {
                    $failures[] = new ConversionFailure(
                        page_slug: $resolvedSlug,
                        page_title: $nav->label,
                        page_node_id: $page->page_node_id,
                        stage: ConversionStage::DraftLanding,
                        reason: "nav reconciliation: page '{$resolvedSlug}' matched a depth-0 kept_page but produced no PuckOutput",
                    );
                }

                continue;
            }

            $resolved[] = new ResolvedNavItem(
                label: $nav->label,
                page_slug: $resolvedSlug,
                order: $nav->order,
                status: ResolvedNavStatus::Resolved,
                note: null,
            );
        }

        return [$resolved, $failures];
    }

    /**
     * @return array<string, array<int, InventoryPage>> label => list of depth-0 InventoryPages
     */
    private function indexDepthZeroByLabel(SitePlan $plan): array
    {
        /** @var array<string, array<int, InventoryPage>> $out */
        $out = [];
        /** @var array<int, InventoryPage> $pages */
        $pages = $plan->kept_pages->items();
        foreach ($pages as $page) {
            if ($page->depth !== 0) {
                continue;
            }
            $out[$page->label][] = $page;
        }

        return $out;
    }

    /**
     * Truth table:
     *   AssemblyStatus::Failed → ConversionStatus::Failed (regardless of platform)
     *   any failures present  → at minimum Partial
     *   AssemblyStatus::Partial OR PlatformRenderStatus::Partial → Partial
     *   otherwise → Completed
     *
     * @param  array<int, ConversionFailure>  $failures
     */
    private function resolveStatus(
        AssemblyResult $assembly,
        PlatformRenderResult $platform,
        array $failures,
    ): ConversionStatus {
        if ($assembly->status === AssemblyStatus::Failed) {
            return ConversionStatus::Failed;
        }
        if ($failures !== []
            || $assembly->status === AssemblyStatus::Partial
            || $platform->status === PlatformRenderStatus::Partial) {
            return ConversionStatus::Partial;
        }

        return ConversionStatus::Completed;
    }

    /**
     * @param  array<string, array<string, mixed>>  $pageMap
     * @return array{draft_id: ?string, draft_url: ?string, failure: ?ConversionFailure}
     */
    private function callCreateDraftSite(Manifest $manifest, array $pageMap): array
    {
        try {
            // v1 scope cut: provisioning is null on Manifest and always
            // empty here. Site rebuild only.
            $response = $this->productClient->createDraftSite($manifest->org_id, $pageMap, []);

            return [
                'draft_id' => $response['draft_id'],
                'draft_url' => $response['draft_url'],
                'failure' => null,
            ];
        } catch (Throwable $e) {
            return [
                'draft_id' => null,
                'draft_url' => null,
                'failure' => new ConversionFailure(
                    page_slug: '*',
                    page_title: 'createDraftSite call',
                    page_node_id: null,
                    stage: ConversionStage::DraftLanding,
                    reason: 'createDraftSite threw: '.$e->getMessage(),
                ),
            ];
        }
    }
}
