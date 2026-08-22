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
        private readonly OrgTypeGate $orgTypeGate,
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
            // Slice 15f: orgType gating enforcement. Blocks whose
            // contract type is gated to a subset that excludes the
            // caller's orgType are DROPPED with an error-severity
            // diagnostic. Contract Part II is explicit that a gated
            // block for the wrong orgType is an ERROR, not a silent
            // drop — the block would not even appear in the org's
            // palette on the receiving side.
            [$gatedBlocks, $gatedDiagnostics] = $this->orgTypeGate->apply(
                $mapped->blocks,
                $orgType,
                $sourceSlug ?? '(unknown)',
            );
            foreach ($gatedDiagnostics as $d) {
                $extraDiagnostics[] = $d;
            }

            // Repair id collisions within the page. The mapper's ids
            // are deterministic content-hashes, so two identically-
            // authored blocks ("Description text.") collide. Contract
            // Part II acknowledges ingest re-checks + repairs, but we
            // fix it here so our output is contract-clean by
            // construction rather than depending on TeamLinkt's repair.
            $repairedBlocks = $this->repairBlockIds($gatedBlocks);
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
     * Recurses into slot props (Grid.column1..4, Tabs.tab1..4,
     * Section.content). A block inside a slot must also validate;
     * this is where a Grid whose slot children include an
     * unknown-type block would surface.
     *
     * @param  array<int, ValidationIssue>  $errors  by-reference
     * @param  array<int, ValidationIssue>  $warnings  by-reference
     */
    private function validateBlockRecursively(
        Block $block,
        string $path,
        array &$errors,
        array &$warnings,
    ): void {
        foreach ($this->blockValidator->validateBlock($block, $path) as $issue) {
            if ($issue->severity === 'error') {
                $errors[] = $issue;
            } else {
                $warnings[] = $issue;
            }
        }
        // Recurse into any prop whose value is a list of Block-
        // shaped arrays (a slot). Contract's slot props: Grid.
        // column1..4, Tabs.tab1..4, Section.content.
        foreach ($block->props as $key => $value) {
            if (! is_string($key) || ! is_array($value)) {
                continue;
            }
            if (! $this->isBlockList($value)) {
                continue;
            }
            foreach ($value as $i => $child) {
                if (! is_array($child) || ! is_string($child['type'] ?? null)) {
                    continue;
                }
                $childBlock = new Block(
                    type: (string) $child['type'],
                    props: is_array($child['props'] ?? null) ? $child['props'] : [],
                );
                $this->validateBlockRecursively(
                    $childBlock,
                    "{$path}.props.{$key}[{$i}]",
                    $errors,
                    $warnings,
                );
            }
        }
    }

    /**
     * @param  array<string, true>  $seen  by-reference: accumulates seen ids
     * @param  array<int, ValidationIssue>  $errors  by-reference
     */
    private function collectBlockIdsRecursively(
        Block $block,
        array &$seen,
        array &$errors,
        string $pageSlug,
        string $path,
    ): void {
        $id = $block->props['id'] ?? null;
        if (is_string($id) && $id !== '') {
            if (isset($seen[$id])) {
                $errors[] = new ValidationIssue(
                    severity: 'error',
                    code: 'duplicate_block_id',
                    message: "Duplicate block id `{$id}` within page `{$pageSlug}`.",
                    path: "{$path}.props.id",
                );
            }
            $seen[$id] = true;
        }
        foreach ($block->props as $key => $value) {
            if (! is_string($key) || ! is_array($value) || ! $this->isBlockList($value)) {
                continue;
            }
            foreach ($value as $i => $child) {
                if (! is_array($child) || ! is_string($child['type'] ?? null)) {
                    continue;
                }
                $childBlock = new Block(
                    type: (string) $child['type'],
                    props: is_array($child['props'] ?? null) ? $child['props'] : [],
                );
                $this->collectBlockIdsRecursively(
                    $childBlock,
                    $seen,
                    $errors,
                    $pageSlug,
                    "{$path}.props.{$key}[{$i}]",
                );
            }
        }
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isBlockList(array $value): bool
    {
        if ($value === []) {
            return false;
        }
        foreach ($value as $entry) {
            if ($entry instanceof Block) {
                return true;
            }
            if (! is_array($entry)) {
                return false;
            }
            if (! is_string($entry['type'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{0: array<int, ValidationIssue>, 1: array<int, ValidationIssue>}
     */
    private function validateEnvelope(Envelope $envelope): array
    {
        $errors = [];
        $warnings = [];

        // Contract Part VI self-check rules 1-6: per-block validation.
        // Recurses into slot props (Grid.column<N>, Tabs.tab<N>,
        // Section.content) so nested blocks are validated too.
        $pageIndex = 0;
        foreach ($envelope->pages as $page) {
            /** @var Page $page */
            $blockIndex = 0;
            foreach ($page->data->content as $block) {
                $this->validateBlockRecursively(
                    $block,
                    "pages[{$pageIndex}].data.content[{$blockIndex}]",
                    $errors,
                    $warnings,
                );
                $blockIndex++;
            }
            $pageIndex++;
        }

        // Rule 7: block id uniqueness within page. Walks slot
        // children too (a nested block's id shares the per-page
        // uniqueness space).
        $pageIndex = 0;
        foreach ($envelope->pages as $page) {
            /** @var Page $page */
            $seen = [];
            $bi = 0;
            foreach ($page->data->content as $block) {
                $this->collectBlockIdsRecursively(
                    $block,
                    $seen,
                    $errors,
                    $page->slug,
                    "pages[{$pageIndex}].data.content[{$bi}]",
                );
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

        // Walk every string in every page's content + site settings
        // looking for tl-asset:<ref> tokens. Use the serialised form
        // (nested arrays only, no Block objects) so array_walk_recursive
        // can reach tokens inside slot children (Grid.column<N>[]).
        // Walking $block->props directly would miss those — Block
        // instances inside slots are objects, and array_walk_recursive
        // stops at objects.
        $serialised = $envelope->toArray();
        foreach (($serialised['pages'] ?? []) as $pi => $pageData) {
            $content = $pageData['data']['content'] ?? [];
            if (! is_array($content)) {
                continue;
            }
            foreach ($this->extractTokens($content) as $tokenRef) {
                if (! isset($declaredRefs[$tokenRef])) {
                    $issues[] = new ValidationIssue(
                        severity: 'error',
                        code: 'unreferenced_asset_token',
                        message: "Block references `tl-asset:{$tokenRef}` but no assets[] entry declares it.",
                        path: "pages[{$pi}].data.content",
                    );
                } else {
                    $declaredRefs[$tokenRef] = true;
                }
            }
        }
        // Also scan site settings for tokens.
        $siteJson = $serialised['site'] ?? [];
        if (is_array($siteJson)) {
            foreach ($this->extractTokens($siteJson) as $tokenRef) {
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
     * page. Walks slot children too so ids across Grid.column<N>
     * share the per-page uniqueness space with top-level blocks.
     *
     * @param  array<int, Block>  $blocks
     * @return array<int, Block>
     */
    private function repairBlockIds(array $blocks): array
    {
        $seen = [];
        $out = [];
        foreach ($blocks as $block) {
            $out[] = $this->repairOne($block, $seen);
        }

        return $out;
    }

    /**
     * @param  array<string, true>  $seen  by-reference
     */
    private function repairOne(Block $block, array &$seen): Block
    {
        $id = $block->props['id'] ?? '';
        $originalId = is_string($id) ? $id : '';
        $candidate = $originalId;
        $suffix = 2;
        while ($candidate !== '' && isset($seen[$candidate])) {
            $candidate = "{$originalId}-{$suffix}";
            $suffix++;
        }
        $seen[$candidate] = true;

        $newProps = $block->props;
        if ($candidate !== $originalId) {
            $newProps['id'] = $candidate;
        }
        // Recurse into slot children. Slot children are stored as
        // array form (mapper's Grid emit uses ->toArray()) and MUST
        // stay as arrays through repair — re-wrapping into Block
        // objects here breaks array_walk_recursive downstream at
        // the token-extractor pass.
        foreach ($newProps as $key => $value) {
            if (! is_string($key) || ! is_array($value) || ! $this->isBlockList($value)) {
                continue;
            }
            $repairedChildren = [];
            foreach ($value as $child) {
                if (! is_array($child) || ! is_string($child['type'] ?? null)) {
                    continue;
                }
                $childBlock = new Block(
                    type: (string) $child['type'],
                    props: is_array($child['props'] ?? null) ? $child['props'] : [],
                );
                // Preserve ARRAY form after id-repair.
                $repairedChildren[] = $this->repairOne($childBlock, $seen)->toArray();
            }
            $newProps[$key] = $repairedChildren;
        }

        return $newProps === $block->props ? $block : new Block(type: $block->type, props: $newProps);
    }
}
