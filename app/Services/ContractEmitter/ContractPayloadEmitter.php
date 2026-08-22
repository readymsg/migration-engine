<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\ConversionResult;
use App\Data\OrgType;
use App\Data\SiteImport\Asset;
use App\Data\SiteImport\Block;
use App\Data\SiteImport\Diagnostic;
use App\Data\SiteImport\Envelope;
use App\Data\SiteImport\Page;
use App\Data\SiteImport\PageData;
use App\Data\SiteImport\Source;
use App\Data\SiteImport\ValidationIssue;
use Spatie\LaravelData\DataCollection;

// Top-level orchestrator. Composes Slice 4 (AssetLedger) + Slice 5
// (PuckToContractMapper) + Slice 6 (PageTreeBuilder) + Slice 7
// (SiteSettingsEmitter) + Slice 8 (DiagnosticsCollector) into a
// contract Envelope.
//
// The pipeline is deliberately dependency-injected so the top-level
// composition is testable end-to-end AND each collaborator can be
// swapped independently (e.g. Slice 14 swaps ContractSchema's data
// source from the hand-encoded JSON to engineering's real
// ai-website-builder-schema.json without touching this orchestrator).
//
// STEP ORDER (matters):
//   1. SiteSettings first — the logo-token registers early so the
//      ledger's order is deterministic (site-logo before content).
//   2. Page tree — establishes source-slug → page-id mapping.
//   3. Per-page content mapping — runs PuckToContractMapper against
//      each page's old-schema content, populates Page.data.content.
//   4. Assets — read out the (now-fully-populated) ledger.
//   5. Diagnostics — combine every stage's issue stream.
//   6. Envelope validation — Contract Part VI self-check rules
//      1-11 across the finished envelope. Rules 1-6 are per-block
//      via ContractSchemaValidator; 7-11 are envelope-level and
//      enforced here.
final class ContractPayloadEmitter
{
    public function __construct(
        private readonly PageTreeBuilder $pageTreeBuilder,
        private readonly PuckToContractMapper $mapper,
        private readonly SiteSettingsEmitter $siteSettingsEmitter,
        private readonly DiagnosticsCollector $diagnosticsCollector,
        private readonly ContractSchemaValidator $blockValidator,
    ) {}

    public function emit(
        ConversionResult $result,
        OrgType $orgType,
        ?string $scrapedAt = null,
    ): EmitResult {
        $ledger = new AssetLedger;
        $assetContext = new AssetContext($result->asset_refs);
        $extraDiagnostics = [];

        // Step 1: SiteSettings (may register logo token).
        $site = $this->siteSettingsEmitter->emit($result, $ledger);

        // Step 2: Page tree shells.
        $tree = $this->pageTreeBuilder->build($result);
        foreach ($tree->diagnostics as $d) {
            $extraDiagnostics[] = $d;
        }

        // Step 3: Per-page content mapping.
        $filledPages = [];
        foreach ($tree->pages as $page) {
            $sourceSlug = $this->findSourceSlug($tree->pageIdBySourceSlug, $page->id);
            $sourceContent = [];
            if ($sourceSlug !== null && isset($result->page_map[$sourceSlug])) {
                $puckPage = $result->page_map[$sourceSlug];
                if (is_array($puckPage['content'] ?? null)) {
                    $sourceContent = $puckPage['content'];
                }
            }
            $mapped = $this->mapper->mapContent(
                content: $sourceContent,
                assetContext: $assetContext,
                ledger: $ledger,
                sourcePageUrl: $result->source_url,
            );
            foreach ($mapped->diagnostics as $d) {
                $extraDiagnostics[] = $d;
            }
            // Repair id collisions within the page. The mapper's ids
            // are deterministic content-hashes, so two identically-
            // authored blocks ("Description text.") collide. Contract
            // Part II acknowledges ingest re-checks + repairs, but we
            // fix it here so our output is contract-clean by
            // construction rather than depending on TeamLinkt's repair.
            $repairedBlocks = $this->repairBlockIds($mapped->blocks);
            $filledPages[] = new Page(
                id: $page->id,
                slug: $page->slug,
                title: $page->title,
                parentId: $page->parentId,
                navOrder: $page->navOrder,
                showInNav: $page->showInNav,
                data: new PageData(
                    content: new DataCollection(Block::class, $repairedBlocks),
                ),
            );
        }

        // Step 4: Assets read from the (populated) ledger.
        $assets = $ledger->all();

        // Step 5: Diagnostics — combine extras + result-derived.
        $diagnostics = $this->diagnosticsCollector->collect($result, $extraDiagnostics);

        // Build the envelope.
        $envelope = new Envelope(
            schemaVersion: Envelope::SCHEMA_VERSION,
            source: new Source(
                url: $result->source_url,
                scrapedAt: $scrapedAt ?? gmdate('Y-m-d\TH:i:s\Z'),
                pagesDiscovered: count($result->page_map),
                pagesMapped: count($filledPages),
            ),
            site: $site,
            pages: new DataCollection(Page::class, $filledPages),
            assets: $assets,
            diagnostics: new DataCollection(Diagnostic::class, $diagnostics),
        );

        // Step 6: Validate.
        [$errors, $warnings] = $this->validateEnvelope($envelope);

        return new EmitResult(envelope: $envelope, errors: $errors, warnings: $warnings);
    }

    /**
     * @return array{0: array<int, ValidationIssue>, 1: array<int, ValidationIssue>}
     */
    private function validateEnvelope(Envelope $envelope): array
    {
        $errors = [];
        $warnings = [];

        // Contract Part VI self-check rules 1-6: per-block validation.
        $pageIndex = 0;
        foreach ($envelope->pages as $page) {
            /** @var Page $page */
            $blockIndex = 0;
            foreach ($page->data->content as $block) {
                foreach ($this->blockValidator->validateBlock(
                    $block,
                    "pages[{$pageIndex}].data.content[{$blockIndex}]",
                ) as $issue) {
                    if ($issue->severity === 'error') {
                        $errors[] = $issue;
                    } else {
                        $warnings[] = $issue;
                    }
                }
                // Per-page block-id uniqueness (Contract Part I rule 5).
                $blockIndex++;
            }
            // ...checked below across all blocks on a page.
            $pageIndex++;
        }

        // Rule 7: block id uniqueness within page.
        $pageIndex = 0;
        foreach ($envelope->pages as $page) {
            /** @var Page $page */
            $seen = [];
            $bi = 0;
            foreach ($page->data->content as $block) {
                /** @var Block $block */
                $id = $block->props['id'] ?? null;
                if (is_string($id) && $id !== '') {
                    if (isset($seen[$id])) {
                        $errors[] = new ValidationIssue(
                            severity: 'error',
                            code: 'duplicate_block_id',
                            message: "Duplicate block id `{$id}` within page `{$page->slug}`.",
                            path: "pages[{$pageIndex}].data.content[{$bi}].props.id",
                        );
                    }
                    $seen[$id] = true;
                }
                $bi++;
            }
            $pageIndex++;
        }

        // Rule 8: exactly one page with slug="".
        $homeSlugs = 0;
        $slugsCanonical = [];
        $pi = 0;
        foreach ($envelope->pages as $page) {
            /** @var Page $page */
            if ($page->slug === '') {
                $homeSlugs++;
            } else {
                $canonical = strtolower($page->slug);
                if (isset($slugsCanonical[$canonical])) {
                    // Should have been caught by PageTreeBuilder;
                    // envelope-level check is a defence-in-depth.
                    $errors[] = new ValidationIssue(
                        severity: 'error',
                        code: 'duplicate_slug',
                        message: "Duplicate slug `{$page->slug}` (CI). Contract requires unique slugs per site.",
                        path: "pages[{$pi}].slug",
                    );
                }
                $slugsCanonical[$canonical] = true;

                // Rule 10: view-prefixed.
                if ($page->slug === 'view' || str_starts_with($page->slug, 'view/')) {
                    $errors[] = new ValidationIssue(
                        severity: 'error',
                        code: 'reserved_view_slug',
                        message: "Slug `{$page->slug}` uses the reserved `view*` prefix.",
                        path: "pages[{$pi}].slug",
                    );
                }
            }
            $pi++;
        }
        if ($homeSlugs !== 1) {
            $errors[] = new ValidationIssue(
                severity: 'error',
                code: 'homepage_count_wrong',
                message: "Expected exactly one page with slug=\"\"; got {$homeSlugs}. Contract Part II 'Slug rules'.",
                path: 'pages[*].slug',
            );
        }

        // Rule 9: tl-asset:<ref>/assets[] reconciliation.
        foreach ($this->validateAssetTokens($envelope) as $issue) {
            if ($issue->severity === 'error') {
                $errors[] = $issue;
            } else {
                $warnings[] = $issue;
            }
        }

        // Rule 11: data.root and data.zones empty per page.
        $pi = 0;
        foreach ($envelope->pages as $page) {
            /** @var Page $page */
            if ($page->data->root !== []) {
                $errors[] = new ValidationIssue(
                    severity: 'error',
                    code: 'page_root_not_empty',
                    message: 'data.root must be {} on every page; site chrome is spliced by the builder at load time.',
                    path: "pages[{$pi}].data.root",
                );
            }
            if ($page->data->zones !== []) {
                $errors[] = new ValidationIssue(
                    severity: 'error',
                    code: 'page_zones_not_empty',
                    message: 'data.zones must be {} on every page; nesting goes in slot props, not zones.',
                    path: "pages[{$pi}].data.zones",
                );
            }
            $pi++;
        }

        // parentId hygiene: every non-null parentId must name an
        // existing page id in the same payload.
        $pageIds = [];
        foreach ($envelope->pages as $p) {
            /** @var Page $p */
            $pageIds[$p->id] = true;
        }
        $pi = 0;
        foreach ($envelope->pages as $page) {
            /** @var Page $page */
            if ($page->parentId !== null && ! isset($pageIds[$page->parentId])) {
                $errors[] = new ValidationIssue(
                    severity: 'error',
                    code: 'parent_id_dangling',
                    message: "parentId `{$page->parentId}` does not name a page in this payload.",
                    path: "pages[{$pi}].parentId",
                );
            }
            $pi++;
        }

        return [$errors, $warnings];
    }

    /**
     * @return array<int, ValidationIssue>
     */
    private function validateAssetTokens(Envelope $envelope): array
    {
        $issues = [];

        // Collect declared refs from assets[].
        $declaredRefs = [];
        foreach ($envelope->assets as $asset) {
            /** @var Asset $asset */
            $declaredRefs[$asset->ref] = false; // false = not yet referenced
        }

        // Walk every string prop (recursive into arrays + objects)
        // in every page's content array + the site settings, looking
        // for tl-asset:<ref> tokens. Every one must have a declared
        // ref; every declared ref should be referenced at least once.
        $pi = 0;
        foreach ($envelope->pages as $page) {
            /** @var Page $page */
            $bi = 0;
            foreach ($page->data->content as $block) {
                foreach ($this->extractTokens($block->props) as $tokenRef) {
                    if (! isset($declaredRefs[$tokenRef])) {
                        $issues[] = new ValidationIssue(
                            severity: 'error',
                            code: 'unreferenced_asset_token',
                            message: "Block references `tl-asset:{$tokenRef}` but no assets[] entry declares it.",
                            path: "pages[{$pi}].data.content[{$bi}].props",
                        );
                    } else {
                        $declaredRefs[$tokenRef] = true;
                    }
                }
                $bi++;
            }
            $pi++;
        }
        // Also scan site settings for tokens.
        foreach ($this->extractTokens((array) $envelope->site->toArray()) as $tokenRef) {
            if (! isset($declaredRefs[$tokenRef])) {
                $issues[] = new ValidationIssue(
                    severity: 'error',
                    code: 'unreferenced_asset_token',
                    message: "site references `tl-asset:{$tokenRef}` but no assets[] entry declares it.",
                    path: 'site',
                );
            } else {
                $declaredRefs[$tokenRef] = true;
            }
        }

        // Orphaned assets: declared but not referenced. Warning
        // (contract Part II "assets should be referenced" — soft).
        foreach ($declaredRefs as $ref => $wasUsed) {
            if (! $wasUsed) {
                $issues[] = new ValidationIssue(
                    severity: 'warning',
                    code: 'orphaned_asset',
                    message: "assets[] declares `{$ref}` but no props reference it.",
                    path: 'assets',
                );
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>|array<int, mixed>  $data
     * @return array<int, string>
     */
    private function extractTokens(array $data): array
    {
        $found = [];
        array_walk_recursive($data, function ($v) use (&$found): void {
            if (is_string($v) && str_starts_with($v, 'tl-asset:')) {
                $found[] = substr($v, strlen('tl-asset:'));
            }
        });

        return $found;
    }

    /**
     * @param  array<string, string>  $pageIdBySourceSlug
     */
    private function findSourceSlug(array $pageIdBySourceSlug, string $pageId): ?string
    {
        foreach ($pageIdBySourceSlug as $sourceSlug => $id) {
            if ($id === $pageId) {
                return $sourceSlug;
            }
        }

        return null;
    }

    /**
     * Post-mapper repair: rename any duplicate props.id within a
     * page. Two identically-authored blocks (e.g. two "Description
     * text." Text blocks) produce identical hash-based ids from the
     * mapper's deterministic minter; the first one keeps the id and
     * subsequent duplicates get a `-<counter>` suffix. This is a
     * per-page pass — cross-page collisions are fine because ids are
     * per-page unique.
     *
     * @param  array<int, Block>  $blocks
     * @return array<int, Block>
     */
    private function repairBlockIds(array $blocks): array
    {
        $seen = [];
        $out = [];
        foreach ($blocks as $block) {
            $id = $block->props['id'] ?? '';
            $originalId = is_string($id) ? $id : '';
            $candidate = $originalId;
            $suffix = 2;
            while ($candidate !== '' && isset($seen[$candidate])) {
                $candidate = "{$originalId}-{$suffix}";
                $suffix++;
            }
            $seen[$candidate] = true;
            if ($candidate !== $originalId) {
                $newProps = $block->props;
                $newProps['id'] = $candidate;
                $out[] = new Block(type: $block->type, props: $newProps);
            } else {
                $out[] = $block;
            }
        }

        return $out;
    }
}
