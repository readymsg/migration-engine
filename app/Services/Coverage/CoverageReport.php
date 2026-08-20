<?php

declare(strict_types=1);

namespace App\Services\Coverage;

use App\Data\AssignedBlock;
use App\Data\AssignmentDisposition;
use App\Data\ReconciledElement;
use App\Data\ScrubIssue;
use App\Data\SourceElement;
use App\Data\TeamlinktBlockBucket;
use App\Services\Generate\BlockTypeAssigner;

// Deterministic coverage-report generator.
//
// The report has TWO numbers, deliberately kept distinct:
//
//   1. Element-level coverage (the real capture number). For every
//      SourceElement counted from the scraped markdown, the reconciler
//      classifies it as CAPTURED, SUPERSEDED, or DROPPED. The ratio
//      (captured + superseded) / total is what "did our rebuild
//      preserve the site" means at the demo table.
//
//   2. Block-type assignment. For every Puck block placed in the
//      rebuild, the assigner records which TeamlinktBlockType it maps
//      to. This tells us whether the vocabulary covers our shapes.
//      It is NOT a coverage number — a page can have 100% block-type
//      assignment and still have dropped half its source content.
//
// The DROPPED list is the point of the artifact. It surfaces per page,
// consolidated site-wide ranked by frequency, each with its source
// snippet.
final class CoverageReport
{
    public function __construct(
        private readonly BlockTypeAssigner $assigner,
        private readonly SourceElementCounter $counter,
        private readonly CoverageReconciler $reconciler,
        private readonly ExclusionRules $exclusions = new ExclusionRules,
        private readonly OutOfScopeRules $outOfScope = new OutOfScopeRules,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $pageMap  ConversionResult.page_map
     * @param  array<string, string>  $pageTitles  page_slug → title (defaults to root.title)
     * @param  array<string, string>  $pageMarkdown  page_slug → source markdown (empty string OK)
     * @param  array<string, array<int, ScrubIssue|array<string, mixed>>>  $scrubIssuesBySlug
     * @param  array<int, array{page_title: string, url?: string, disposition: string, reason: string}>  $extraPages
     * @param  array{org_id?: string, source_url?: string, conversion_id?: string, status?: string, brand_palette?: array<string, string>, style_brief_palette?: array<string, string>}  $meta  source_url is used by ExclusionRules; brand_palette + style_brief_palette drive the palette-provenance line
     */
    public function render(
        array $pageMap,
        array $pageTitles,
        array $pageMarkdown,
        array $scrubIssuesBySlug = [],
        array $extraPages = [],
        array $meta = [],
    ): string {
        $sourceOrigin = is_string($meta['source_url'] ?? null) ? $meta['source_url'] : null;
        $assignments = $this->assigner->assign($pageMap, $pageTitles, $scrubIssuesBySlug);

        /** @var array<string, array<int, AssignedBlock>> $bySlug */
        $bySlug = [];
        foreach ($assignments as $a) {
            $bySlug[$a->page_slug][] = $a;
        }

        // Per-page element extraction + reconciliation.
        /** @var array<string, array<string, int>> $perPageCounts */
        $perPageCounts = [];
        /** @var array<string, array<string, array<int, string>>> $perPageSamples */
        $perPageSamples = [];
        /** @var array<string, array<int, ReconciledElement>> $perPageReconciled */
        $perPageReconciled = [];
        foreach ($pageMarkdown as $slug => $md) {
            $c = $this->counter->count($md);
            $perPageCounts[$slug] = $c['counts'];
            $perPageSamples[$slug] = $c['samples'];
            /** @var array<int, SourceElement> $elements */
            $elements = $c['elements'];
            $title = $pageTitles[$slug] ?? $this->titleFromPayload($pageMap[$slug] ?? [], $slug);
            $puckPayload = $pageMap[$slug] ?? ['content' => [], 'root' => [], 'zones' => []];
            $perPageReconciled[$slug] = $this->reconciler->reconcile(
                elements: $elements,
                puckPayload: $puckPayload,
                scrubs: $scrubIssuesBySlug[$slug] ?? [],
                sourceOrigin: $sourceOrigin,
                pageTitle: $title,
            );
        }

        // Site totals — element-level (the real coverage number).
        $siteCapturedEl = 0;
        $siteSupersededEl = 0;
        $siteExcludedEl = 0;
        $siteScopedEl = 0;
        $siteDroppedEl = 0;
        foreach ($perPageReconciled as $rs) {
            foreach ($rs as $r) {
                match ($r->disposition) {
                    'captured' => $siteCapturedEl++,
                    'superseded' => $siteSupersededEl++,
                    'excluded' => $siteExcludedEl++,
                    'out_of_scope' => $siteScopedEl++,
                    'dropped' => $siteDroppedEl++,
                    default => null,
                };
            }
        }
        $siteElementsTotal = $siteCapturedEl + $siteSupersededEl + $siteExcludedEl + $siteScopedEl + $siteDroppedEl;

        // Site totals — block-type assignment (secondary metric).
        $siteAsgCaptured = 0;
        $siteAsgSuperseded = 0;
        $siteAsgUnmapped = 0;
        foreach ($assignments as $a) {
            match ($a->disposition) {
                AssignmentDisposition::Captured => $siteAsgCaptured++,
                AssignmentDisposition::Superseded => $siteAsgSuperseded++,
                AssignmentDisposition::Unmapped => $siteAsgUnmapped++,
                // BlockTypeAssigner emits only the three block-level
                // dispositions above; the other cases are unused here.
                AssignmentDisposition::Excluded,
                AssignmentDisposition::OutOfScope => null,
            };
        }
        $siteBlockDenominator = $siteAsgCaptured + $siteAsgSuperseded + $siteAsgUnmapped;

        $out = [];
        $out[] = '# Migration coverage report';
        $out[] = '';
        if (isset($meta['conversion_id'])) {
            $out[] = '**Conversion:** '.$meta['conversion_id'];
        }
        if (isset($meta['org_id'])) {
            $out[] = '**Org:** '.$meta['org_id'];
        }
        if (isset($meta['source_url'])) {
            $out[] = '**Source URL:** '.$meta['source_url'];
        }
        if (isset($meta['status'])) {
            $out[] = '**Status:** '.$meta['status'];
        }
        $out[] = '**Generated:** '.date('Y-m-d H:i:s T');
        $out[] = '';

        // --- palette provenance ---------------------------------------
        // Which palette is painting pixels in the preview: measured
        // from logo (deterministic, grounded in actual bytes) or
        // LLM-inferred (Opus guess, non-deterministic across runs).
        // Same precedence rule as App.paletteWithProvenance in the
        // preview bundle. Reported so a reviewer can point at the
        // report and say "these are the club's actual colors".
        $measured = is_array($meta['brand_palette'] ?? null) ? $meta['brand_palette'] : [];
        $llm = is_array($meta['style_brief_palette'] ?? null) ? $meta['style_brief_palette'] : [];
        if ($measured !== [] || $llm !== []) {
            $out[] = '## Palette provenance';
            $out[] = '';
            if ($measured !== []) {
                $out[] = '**Source: measured from logo pixels** (deterministic — LogoPaletteExtractor).';
                $out[] = '';
                $out[] = 'Active palette:';
                foreach ($measured as $name => $hex) {
                    $out[] = sprintf('- %s: `%s`', $name, $hex);
                }
                if ($llm !== []) {
                    $out[] = '';
                    $out[] = '_LLM also guessed:_ '.implode(', ', array_map(
                        static fn ($k, $v) => "`{$k}={$v}`",
                        array_keys($llm),
                        array_values($llm),
                    )).' — measured palette wins on precedence.';
                }
            } else {
                $out[] = '**Source: LLM-inferred** (Opus guess — no measured logo palette available).';
                $out[] = '';
                $out[] = 'Active palette:';
                foreach ($llm as $name => $hex) {
                    $out[] = sprintf('- %s: `%s`', $name, $hex);
                }
                $out[] = '';
                $out[] = '_BrandExtractor produced an empty Brand.palette — logo unreachable or not provided; falling back to the LLM-inferred palette from the IR brief._';
            }
            $out[] = '';
        }

        // --- site summary — element-level coverage ---------------------
        $out[] = '## Site summary — content coverage (element-level)';
        $out[] = '';
        $out[] = sprintf('- Pages rebuilt: **%d**', count($pageMap));
        $out[] = sprintf('- Pages set aside (parked / unmapped): **%d**', count($extraPages));
        $out[] = sprintf('- Source elements counted: **%d**', $siteElementsTotal);
        $out[] = sprintf(
            '- Element dispositions: captured **%d**, superseded **%d**, excluded **%d**, out-of-scope **%d**, DROPPED **%d**',
            $siteCapturedEl,
            $siteSupersededEl,
            $siteExcludedEl,
            $siteScopedEl,
            $siteDroppedEl,
        );
        // TWO ratios reported deliberately.
        //   Migratable coverage — the HEADLINE. DROPPED = unintentional
        //   loss; EXCLUDED (source-platform chrome) and OUT_OF_SCOPE
        //   (product-scoping decisions) are deliberate, so they don't
        //   belong in the failure denominator.
        //   Raw capture rate — the TRANSPARENCY line. What fraction of
        //   ALL source elements actually landed. Prevents the headline
        //   from silently drifting up as the scoping rules grow.
        $migratable = $siteElementsTotal - $siteExcludedEl - $siteScopedEl;
        if ($migratable > 0) {
            $ratio = ($siteCapturedEl + $siteSupersededEl) / $migratable;
            $out[] = sprintf(
                '- **Migratable coverage: %.1f%%** = (captured + superseded) / (total − excluded − out-of-scope) — %d / %d',
                $ratio * 100,
                $siteCapturedEl + $siteSupersededEl,
                $migratable,
            );
        }
        if ($siteElementsTotal > 0) {
            $raw = ($siteCapturedEl + $siteSupersededEl) / $siteElementsTotal;
            $out[] = sprintf(
                '- Raw capture rate: %.1f%% = (captured + superseded) / total source elements — %d / %d (transparency; migratable coverage is the headline)',
                $raw * 100,
                $siteCapturedEl + $siteSupersededEl,
                $siteElementsTotal,
            );
        }
        $out[] = '';

        // --- site summary — block-type assignment (secondary) ---------
        $out[] = '## Site summary — block-type assignment (secondary metric)';
        $out[] = '';
        $out[] = sprintf('- Blocks placed: **%d**', $siteBlockDenominator);
        $out[] = sprintf(
            '- Block-type dispositions: captured **%d**, superseded **%d**, unmapped **%d**',
            $siteAsgCaptured,
            $siteAsgSuperseded,
            $siteAsgUnmapped,
        );
        $out[] = '';
        $out[] = '_This measures whether the vocabulary covers our shapes, NOT whether source content survived. Use the content-coverage number above for that._';
        $out[] = '';

        // --- EXCLUDED (intentional discards) --------------------------
        $out[] = '## EXCLUDED — source elements INTENTIONALLY not migrated';
        $out[] = '';
        if ($siteExcludedEl === 0) {
            $out[] = '_No source elements matched an exclusion rule._';
            $out[] = '';
        } else {
            /** @var array<string, int> $excludedByRule */
            $excludedByRule = [];
            /** @var array<string, string> $excludedRuleExample */
            $excludedRuleExample = [];
            foreach ($perPageReconciled as $rs) {
                foreach ($rs as $r) {
                    if ($r->disposition !== 'excluded') {
                        continue;
                    }
                    $rule = str_starts_with($r->evidence, 'rule:') ? substr($r->evidence, 5) : '(unnamed rule)';
                    $excludedByRule[$rule] = ($excludedByRule[$rule] ?? 0) + 1;
                    $excludedRuleExample[$rule] ??= $r->source->snippet;
                }
            }
            arsort($excludedByRule);
            $out[] = sprintf(
                '_Total: **%d** excluded elements — deliberately not migrated per the rules below._',
                $siteExcludedEl,
            );
            $out[] = '';
            $out[] = '| rule | count | example snippet |';
            $out[] = '| --- | ---: | --- |';
            foreach ($excludedByRule as $rule => $n) {
                $out[] = sprintf(
                    '| %s | %d | %s |',
                    $this->tableCell($rule),
                    $n,
                    $this->tableCell($this->truncate($excludedRuleExample[$rule] ?? '', 90)),
                );
            }
            $out[] = '';
        }

        $out[] = '### Exclusion rules';
        $out[] = '';
        $out[] = 'The rules below classify a source element as EXCLUDED. These are counted separately from DROPPED — DROPPED means unintentional content loss.';
        $out[] = '';
        foreach ($this->exclusions->ruleSummary() as $r) {
            $out[] = sprintf('- **%s** — %s', $r['rule'], $r['description']);
        }
        $out[] = '';

        // --- OUT_OF_SCOPE (product-scoping decisions) -----------------
        $out[] = '## OUT_OF_SCOPE — content types the product has scoped OUT of this version';
        $out[] = '';
        if ($siteScopedEl === 0) {
            $out[] = '_No source elements matched an out-of-scope rule._';
            $out[] = '';
        } else {
            /** @var array<string, int> $scopedByCategory */
            $scopedByCategory = [];
            /** @var array<string, string> $scopedFeatureByCategory */
            $scopedFeatureByCategory = [];
            /** @var array<string, string> $scopedExampleByCategory */
            $scopedExampleByCategory = [];
            foreach ($perPageReconciled as $rs) {
                foreach ($rs as $r) {
                    if ($r->disposition !== 'out_of_scope') {
                        continue;
                    }
                    [$category, $feature] = $this->parseScopedEvidence($r->evidence);
                    $scopedByCategory[$category] = ($scopedByCategory[$category] ?? 0) + 1;
                    $scopedFeatureByCategory[$category] ??= $feature;
                    $scopedExampleByCategory[$category] ??= $r->source->snippet;
                }
            }
            arsort($scopedByCategory);
            $out[] = sprintf(
                '_Total: **%d** out-of-scope elements — deliberately not migrated in this version._',
                $siteScopedEl,
            );
            $out[] = '';
            $out[] = '| category | count | eventual feature | example snippet |';
            $out[] = '| --- | ---: | --- | --- |';
            foreach ($scopedByCategory as $category => $n) {
                $out[] = sprintf(
                    '| %s | %d | %s | %s |',
                    $this->tableCell($category),
                    $n,
                    $this->tableCell($scopedFeatureByCategory[$category] ?? ''),
                    $this->tableCell($this->truncate($scopedExampleByCategory[$category] ?? '', 90)),
                );
            }
            $out[] = '';
        }

        $out[] = '### Out-of-scope rules';
        $out[] = '';
        $out[] = 'The rules below classify a source element as OUT_OF_SCOPE. These are content types the product has deliberately decided not to migrate in this version. Each names the platform feature that will eventually own the content, so nothing is lost — only deferred.';
        $out[] = '';
        foreach ($this->outOfScope->ruleSummary() as $r) {
            $out[] = sprintf('- **%s** (→ %s) — %s', $r['rule'], $r['feature'], $r['description']);
        }
        $out[] = '';

        // --- consolidated DROPPED list (site-wide, ranked) ------------
        /** @var array<string, array<int, array{slug: string, title: string, element: SourceElement}>> $droppedByKind */
        $droppedByKind = [];
        foreach ($perPageReconciled as $slug => $rs) {
            $title = $pageTitles[$slug] ?? $this->titleFromPayload($pageMap[$slug] ?? [], $slug);
            foreach ($rs as $r) {
                if ($r->disposition !== 'dropped') {
                    continue;
                }
                $droppedByKind[$r->source->kind][] = [
                    'slug' => $slug,
                    'title' => $title,
                    'element' => $r->source,
                ];
            }
        }
        uasort($droppedByKind, static fn (array $a, array $b) => count($b) <=> count($a));

        $out[] = '## DROPPED — source elements NOT preserved in the rebuild';
        $out[] = '';
        if ($droppedByKind === []) {
            $out[] = '_None found. Treat this with suspicion — if this is real content, the failure channel has likely been fooled by lenient matching._';
            $out[] = '';
        } else {
            $out[] = sprintf('_Total: **%d** dropped elements across %d kinds._', $siteDroppedEl, count($droppedByKind));
            $out[] = '';
            $out[] = '### Consolidated by kind (ranked by frequency)';
            $out[] = '';
            $out[] = '| kind | count | example snippet | example page |';
            $out[] = '| --- | ---: | --- | --- |';
            foreach ($droppedByKind as $kind => $items) {
                $sample = $items[0]['element']->snippet;
                $samplePage = $items[0]['title'];
                $out[] = sprintf(
                    '| `%s` | %d | %s | %s |',
                    $kind,
                    count($items),
                    $this->tableCell($this->truncate($sample, 90)),
                    $this->tableCell($samplePage),
                );
            }
            $out[] = '';
        }

        // --- extra pages (parked at PLAN) ------------------------------
        if ($extraPages !== []) {
            $out[] = '### Source pages set aside (parked / unmapped at PLAN)';
            $out[] = '';
            $out[] = '| page | disposition | reason | url |';
            $out[] = '| --- | --- | --- | --- |';
            foreach ($extraPages as $p) {
                $title = $p['page_title'] ?? '(untitled)';
                $disp = $p['disposition'] ?? 'unknown';
                $reason = $p['reason'] ?? '';
                $url = $p['url'] ?? '';
                $out[] = sprintf(
                    '| %s | %s | %s | %s |',
                    $this->tableCell($title),
                    $disp,
                    $this->tableCell($reason),
                    $url === '' ? '' : '`'.$url.'`',
                );
            }
            $out[] = '';
        }

        // --- page routing (platform blocks) ---------------------------
        $out[] = '### Page routing (platform blocks placed)';
        $out[] = '';
        $platformRows = [];
        foreach ($assignments as $a) {
            if ($a->bucket !== TeamlinktBlockBucket::Platform) {
                continue;
            }
            if ($a->teamlinkt_type === null) {
                continue;
            }
            $platformRows[] = [
                'page' => $a->page_title,
                'slug' => $a->page_slug,
                'type' => $a->teamlinkt_type->value,
                'source_kind' => $a->source_kind,
            ];
        }
        if ($platformRows === []) {
            $out[] = '_No platform blocks placed on this site._';
            $out[] = '';
        } else {
            $out[] = '| page | slug | platform block | source kind |';
            $out[] = '| --- | --- | --- | --- |';
            foreach ($platformRows as $r) {
                $out[] = sprintf(
                    '| %s | `%s` | `%s` | `%s` |',
                    $this->tableCell($r['page']),
                    $r['slug'],
                    $r['type'],
                    $r['source_kind'],
                );
            }
            $out[] = '';
        }

        // --- per-page detail ------------------------------------------
        $out[] = '## Per-page detail';
        $out[] = '';
        foreach ($pageMap as $slug => $payload) {
            $title = $pageTitles[$slug] ?? $this->titleFromPayload($payload, $slug);
            $out[] = "### {$title}";
            $out[] = "*slug:* `{$slug}`";
            $out[] = '';

            // element counts
            $counts = $perPageCounts[$slug] ?? [];
            $samples = $perPageSamples[$slug] ?? [];
            $out[] = '#### Source elements counted';
            if ($counts === [] || array_sum($counts) === 0) {
                $out[] = '_No source markdown available for this page (scrape not found)._';
                $out[] = '';
            } else {
                $out[] = '';
                $out[] = '| kind | count | sample |';
                $out[] = '| --- | ---: | --- |';
                foreach ($counts as $kind => $n) {
                    if ($n === 0) {
                        continue;
                    }
                    $sample = $samples[$kind][0] ?? '';
                    $out[] = sprintf(
                        '| `%s` | %d | %s |',
                        $kind,
                        $n,
                        $this->tableCell($this->truncate($sample, 100)),
                    );
                }
                $out[] = '';
            }

            // element-level dispositions
            $rs = $perPageReconciled[$slug] ?? [];
            $pCap = 0;
            $pSup = 0;
            $pExc = 0;
            $pDrop = 0;
            /** @var array<int, ReconciledElement> $droppedOnPage */
            $droppedOnPage = [];
            /** @var array<int, ReconciledElement> $supersededOnPage */
            $supersededOnPage = [];
            /** @var array<int, ReconciledElement> $excludedOnPage */
            $excludedOnPage = [];
            /** @var array<int, ReconciledElement> $scopedOnPage */
            $scopedOnPage = [];
            $pScoped = 0;
            foreach ($rs as $r) {
                if ($r->disposition === 'captured') {
                    $pCap++;
                } elseif ($r->disposition === 'superseded') {
                    $pSup++;
                    $supersededOnPage[] = $r;
                } elseif ($r->disposition === 'excluded') {
                    $pExc++;
                    $excludedOnPage[] = $r;
                } elseif ($r->disposition === 'out_of_scope') {
                    $pScoped++;
                    $scopedOnPage[] = $r;
                } elseif ($r->disposition === 'dropped') {
                    $pDrop++;
                    $droppedOnPage[] = $r;
                }
            }
            $pMigratable = $pCap + $pSup + $pDrop;

            $out[] = '#### CAPTURED (elements)';
            if ($pCap === 0) {
                $out[] = '_No source elements confirmed as captured on this page._';
                $out[] = '';
            } else {
                $capturedByKind = $this->tallyByKind(array_values(array_filter($rs, static fn ($r) => $r->disposition === 'captured')));
                $out[] = '';
                $out[] = '| element kind | count |';
                $out[] = '| --- | ---: |';
                foreach ($capturedByKind as $kind => $n) {
                    $out[] = sprintf('| `%s` | %d |', $kind, $n);
                }
                $out[] = '';
            }

            $out[] = '#### SUPERSEDED (elements)';
            if ($supersededOnPage === []) {
                $out[] = '_No superseded elements on this page._';
                $out[] = '';
            } else {
                $out[] = '';
                $out[] = '| element kind | count | reason |';
                $out[] = '| --- | ---: | --- |';
                $tallies = $this->tallyByKind($supersededOnPage);
                $reasonByKind = [];
                foreach ($supersededOnPage as $r) {
                    $reasonByKind[$r->source->kind] ??= $r->reason;
                }
                foreach ($tallies as $kind => $n) {
                    $out[] = sprintf(
                        '| `%s` | %d | %s |',
                        $kind,
                        $n,
                        $this->tableCell($reasonByKind[$kind] ?? ''),
                    );
                }
                $out[] = '';
            }

            $out[] = '#### DROPPED (elements)';
            if ($droppedOnPage === []) {
                $out[] = '_No dropped elements on this page._';
                $out[] = '';
            } else {
                $out[] = '';
                $out[] = '| element kind | snippet |';
                $out[] = '| --- | --- |';
                foreach ($droppedOnPage as $r) {
                    $out[] = sprintf(
                        '| `%s` | %s |',
                        $r->source->kind,
                        $this->tableCell($this->truncate($r->source->snippet, 140)),
                    );
                }
                $out[] = '';
            }

            // Per-page EXCLUDED section (intentional discards).
            $out[] = '#### EXCLUDED (elements)';
            if ($excludedOnPage === []) {
                $out[] = '_No excluded elements on this page._';
                $out[] = '';
            } else {
                $out[] = '';
                $out[] = '| element kind | rule | snippet |';
                $out[] = '| --- | --- | --- |';
                foreach ($excludedOnPage as $r) {
                    $rule = str_starts_with($r->evidence, 'rule:') ? substr($r->evidence, 5) : '(unnamed)';
                    $out[] = sprintf(
                        '| `%s` | %s | %s |',
                        $r->source->kind,
                        $this->tableCell($rule),
                        $this->tableCell($this->truncate($r->source->snippet, 100)),
                    );
                }
                $out[] = '';
            }

            // Per-page OUT_OF_SCOPE section (product-scoping decisions).
            $out[] = '#### OUT_OF_SCOPE (elements)';
            if ($scopedOnPage === []) {
                $out[] = '_No out-of-scope elements on this page._';
                $out[] = '';
            } else {
                $out[] = '';
                $out[] = '| element kind | category | feature | snippet |';
                $out[] = '| --- | --- | --- | --- |';
                foreach ($scopedOnPage as $r) {
                    [$category, $feature] = $this->parseScopedEvidence($r->evidence);
                    $out[] = sprintf(
                        '| `%s` | %s | %s | %s |',
                        $r->source->kind,
                        $this->tableCell($category),
                        $this->tableCell($feature),
                        $this->tableCell($this->truncate($r->source->snippet, 100)),
                    );
                }
                $out[] = '';
            }

            if ($pMigratable > 0) {
                $ratio = ($pCap + $pSup) / $pMigratable;
                // pMigratable > 0 already implies rawDenom >= pMigratable > 0.
                $rawDenom = $pMigratable + $pExc + $pScoped;
                $rawRatio = ($pCap + $pSup) / $rawDenom;
                $out[] = sprintf(
                    '**Migratable coverage:** %.1f%% — captured %d, superseded %d, DROPPED %d, excluded %d, out-of-scope %d (%d migratable of %d total)',
                    $ratio * 100,
                    $pCap,
                    $pSup,
                    $pDrop,
                    $pExc,
                    $pScoped,
                    $pMigratable,
                    $rawDenom,
                );
                $out[] = sprintf(
                    '_Raw capture rate: %.1f%% (%d captured/superseded of %d total source elements)._',
                    $rawRatio * 100,
                    $pCap + $pSup,
                    $rawDenom,
                );
                $out[] = '';
            }

            // Block-type assignment on this page (secondary).
            $pageBlocks = $bySlug[$slug] ?? [];
            $out[] = sprintf(
                '_Block-type assignment on this page: %d blocks — captured %d, superseded %d, unmapped %d._',
                count($pageBlocks),
                count(array_filter($pageBlocks, static fn ($a) => $a->disposition === AssignmentDisposition::Captured)),
                count(array_filter($pageBlocks, static fn ($a) => $a->disposition === AssignmentDisposition::Superseded)),
                count(array_filter($pageBlocks, static fn ($a) => $a->disposition === AssignmentDisposition::Unmapped)),
            );
            $out[] = '';
        }

        return implode("\n", $out)."\n";
    }

    /**
     * OUT_OF_SCOPE evidence is stored as "category:<name>|feature:<name>".
     * Parse into two strings for report rendering.
     *
     * @return array{0: string, 1: string}
     */
    private function parseScopedEvidence(string $evidence): array
    {
        $category = '';
        $feature = '';
        foreach (explode('|', $evidence) as $part) {
            if (str_starts_with($part, 'category:')) {
                $category = substr($part, strlen('category:'));
            } elseif (str_starts_with($part, 'feature:')) {
                $feature = substr($part, strlen('feature:'));
            }
        }

        return [$category, $feature];
    }

    /**
     * @param  array<int, ReconciledElement>  $items
     * @return array<string, int>
     */
    private function tallyByKind(array $items): array
    {
        /** @var array<string, int> $out */
        $out = [];
        foreach ($items as $r) {
            $out[$r->source->kind] = ($out[$r->source->kind] ?? 0) + 1;
        }
        arsort($out);

        return $out;
    }

    private function truncate(string $s, int $max): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max - 1).'…';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function titleFromPayload(array $payload, string $slug): string
    {
        $root = is_array($payload['root'] ?? null) ? $payload['root'] : [];
        $t = $root['title'] ?? null;

        return is_string($t) && $t !== '' ? $t : $slug;
    }

    private function tableCell(string $s): string
    {
        $s = str_replace(['|', "\n", "\r"], [' \\| ', ' ', ' '], $s);

        return trim($s);
    }
}
