<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\DecisionAction;
use App\Data\DecisionEntry;
use App\Data\InventoryPage;
use App\Services\Extract\BrandExtractor;
use App\Services\Extract\HttpHtmlFetcher;
use App\Services\Extract\HttpRootNavFetcher;
use App\Services\Extract\LocalDiskFirecrawlClient;
use App\Services\Extract\S3AssetUploader;
use App\Services\Extract\SeCdnRehoster;
use App\Services\Extract\SportNginExtractor;
use App\Services\Generate\ContentLoader;
use App\Services\Plan\RootNavPlanner;
use App\Services\Plan\SePlatformContentDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Tests\Support\Plan\FakeClassifierAgent;
use Throwable;

// DIAGNOSTIC TOOLING (calibration follow-up). Replays INGEST + PLAN
// against an already-scraped site (uses LocalDiskFirecrawlClient — no
// Firecrawl API call) so we can inspect the SitePlan without paying.
//
// PLAN runs with FakeClassifierAgent (keep@0.85 default) — NOT the live
// Haiku. The deterministic classification paths (node_type-driven
// PlatformDynamic, name-map PlatformDynamic, subsumption, registration
// retarget, SE-platform-link park, SE-platform-content body park,
// external nav) MATCH the live run exactly. The LLM-driven path
// (ambiguous content pages) differs: live Haiku may have parked some;
// the fake keeps everything. So Park counts from this command are a
// LOWER BOUND on what live PLAN would have produced.
//
// Reads scrapes from storage/app/private/orgs/<org>/scrapes/ — requires
// a prior live capture to have populated that directory.
final class PlanReplay extends Command
{
    protected $signature = 'engine:plan-replay {url} {--org-id=}';

    protected $description = 'Replay INGEST (disk scrapes) + PLAN (FakeClassifier) and dump the SitePlan breakdown.';

    public function handle(): int
    {
        $url = (string) $this->argument('url');
        $orgId = (string) ($this->option('org-id') ?? '');

        // If org-id wasn't given, derive it by doing a quick extractor
        // run that includes HTML fetch (HttpHtmlFetcher discovers the
        // SE site_id from the favicon link).
        if ($orgId === '') {
            $this->info('No --org-id; will derive from extractor.');
        }

        // Build the extractor with disk-only Firecrawl. Org-id is
        // resolved from HTML during extraction; we re-bind Firecrawl
        // with the resolved org-id after.
        $this->info('=== INGEST (disk Firecrawl) ===');
        $html = $this->getLaravel()->make(HttpHtmlFetcher::class);
        $nav = $this->getLaravel()->make(HttpRootNavFetcher::class);
        $uploader = new S3AssetUploader(disk: 'local');
        $brand = new BrandExtractor;
        $rehoster = new SeCdnRehoster($uploader);

        // Two-pass: first pass with a dummy Firecrawl client to get the
        // org_id from the manifest extraction (extractor calls HTML
        // fetch first to find site_id). Then re-instantiate with the
        // resolved org's disk-Firecrawl client.
        // SIMPLER: take org-id as required option to avoid two-pass.
        if ($orgId === '') {
            $this->error('Pass --org-id=<ngin-...>; required for disk-Firecrawl wiring.');

            return self::FAILURE;
        }

        $firecrawl = new LocalDiskFirecrawlClient(orgId: $orgId, disk: 'local');
        $extractor = new SportNginExtractor($html, $nav, $firecrawl, $uploader, $brand, $rehoster);

        try {
            $manifest = $extractor->extract($url);
        } catch (Throwable $e) {
            $this->error("INGEST failed: {$e->getMessage()}");

            return self::FAILURE;
        }
        $this->line(sprintf(
            '  org_id=%s  content_refs=%d  asset_refs=%d  content_failures=%d  flags=%d',
            $manifest->org_id,
            $manifest->content_refs->count(),
            $manifest->asset_refs->count(),
            $manifest->content_failures?->count() ?? 0,
            count($manifest->flags),
        ));

        // PLAN with FakeClassifier
        $this->info('=== PLAN (FakeClassifierAgent — keep@0.85 default) ===');
        $planner = new RootNavPlanner(
            new FakeClassifierAgent,
            new ContentLoader(disk: 'local'),
            new SePlatformContentDetector,
        );
        try {
            $plan = $planner->plan($manifest);
        } catch (Throwable $e) {
            $this->error("PLAN failed: {$e->getMessage()}");

            return self::FAILURE;
        }
        $this->line(sprintf(
            '  kept_pages=%d  nav=%d  ledger_entries=%d',
            $plan->kept_pages->count(),
            $plan->nav->count(),
            $plan->ledger->entries->count(),
        ));

        // --- Ledger by action ---
        $this->newLine();
        $this->info('=== Ledger action distribution ===');
        $byAction = [];
        /** @var array<int, DecisionEntry> $entries */
        $entries = $plan->ledger->entries->items();
        foreach ($entries as $e) {
            $byAction[$e->action->value] ??= 0;
            $byAction[$e->action->value]++;
        }
        foreach ($byAction as $k => $c) {
            $this->line(sprintf('  %-20s %d', $k, $c));
        }

        // --- kept_pages breakdown by (kind, action) ---
        $this->newLine();
        $this->info('=== kept_pages × (kind, action) ===');
        $ledgerByTarget = [];
        foreach ($entries as $e) {
            $ledgerByTarget[$e->target] = $e;
        }
        $crossTab = [];
        $irEligible = [];
        $excludedFromIr = [];
        /** @var array<int, InventoryPage> $keptPages */
        $keptPages = $plan->kept_pages->items();
        foreach ($keptPages as $page) {
            $target = $this->targetOf($page);
            $entry = $ledgerByTarget[$target] ?? null;
            $action = $entry?->action->value ?? 'NO_ENTRY';
            $key = "kind={$page->kind}  action={$action}";
            $crossTab[$key] ??= 0;
            $crossTab[$key]++;

            $isIrEligible = ($page->kind === 'page')
                && ($entry !== null)
                && ($entry->action === DecisionAction::Keep);
            if ($isIrEligible) {
                $irEligible[] = $page;
            } else {
                $excludedFromIr[] = ['page' => $page, 'action' => $action];
            }
        }
        foreach ($crossTab as $k => $c) {
            $this->line(sprintf('  %-50s %d', $k, $c));
        }

        $this->newLine();
        $this->info(sprintf(
            '=== IR pass eligibility (kind=page AND action=Keep) ===',
        ));
        $this->line(sprintf('  IR-eligible            : %d', count($irEligible)));
        $this->line(sprintf('  excluded from IR (still in kept_pages) : %d', count($excludedFromIr)));

        // --- Breakdown of excluded-from-IR pages ---
        $this->newLine();
        $this->info('=== Pages in kept_pages NOT eligible for IR ===');
        $reasonBuckets = [
            'kind!=page (external/dynamic_*)' => 0,
            'kind=page + action=PlatformDynamic' => 0,
            'kind=page + action=Dynamic' => 0,
            'kind=page + action=Merge' => 0,
            'kind=page + action=Keep (BUG?)' => 0,
            'other' => 0,
        ];
        $bugList = [];
        foreach ($excludedFromIr as $row) {
            /** @var InventoryPage $page */
            $page = $row['page'];
            $action = $row['action'];
            if ($page->kind !== 'page') {
                $reasonBuckets['kind!=page (external/dynamic_*)']++;
            } elseif ($action === 'platform_dynamic') {
                $reasonBuckets['kind=page + action=PlatformDynamic']++;
            } elseif ($action === 'dynamic') {
                $reasonBuckets['kind=page + action=Dynamic']++;
            } elseif ($action === 'merge') {
                $reasonBuckets['kind=page + action=Merge']++;
            } elseif ($action === 'keep') {
                // Bug suspect: this means the IR filter SHOULD have included it.
                $reasonBuckets['kind=page + action=Keep (BUG?)']++;
                $bugList[] = $page;
            } else {
                $reasonBuckets['other']++;
            }
        }
        foreach ($reasonBuckets as $k => $c) {
            $this->line(sprintf('  %-50s %d', $k, $c));
        }

        if ($bugList !== []) {
            $this->newLine();
            $this->warn('=== BUG SUSPECTS — Keep + kind=page in kept_pages but excluded from IR ===');
            foreach ($bugList as $p) {
                $this->line(sprintf(
                    '  page_node_id=%-12s depth=%d  url=%s  label=%s',
                    (string) ($p->page_node_id ?? '(none)'),
                    $p->depth,
                    $p->url ?? '(none)',
                    $p->label,
                ));
            }
        } else {
            $this->newLine();
            $this->info('✓ No silent-loss suspects: every kept_pages entry that is excluded from IR has a categorisable structural reason.');
        }

        // --- Detail dump of all excluded-from-IR pages (for the report) ---
        $this->newLine();
        $this->info('=== All excluded-from-IR pages (first 60) ===');
        $shown = 0;
        foreach ($excludedFromIr as $row) {
            if ($shown >= 60) {
                $this->line(sprintf('  ... and %d more', count($excludedFromIr) - $shown));
                break;
            }
            /** @var InventoryPage $page */
            $page = $row['page'];
            $action = $row['action'];
            $this->line(sprintf(
                '  kind=%-15s action=%-20s depth=%d  pn=%-12s  label=%s',
                $page->kind,
                $action,
                $page->depth,
                (string) ($page->page_node_id ?? ''),
                substr($page->label, 0, 60),
            ));
            $shown++;
        }

        // --- IR pass with FAKE agents — verifies chunking + reconciliation deterministically ---
        $this->newLine();
        $this->info('=== IR pass (FAKE agents — chunking + reconciliation check) ===');
        $briefAgent = new \Tests\Support\Generate\FakeIrBriefDeriverAgent;
        $designerAgent = new \Tests\Support\Generate\FakeIrChunkDesignerAgent;
        $irPass = new \App\Services\Generate\IrPass(
            $briefAgent,
            $designerAgent,
            new ContentLoader(disk: 'local'),
        );

        $irResult = $irPass->run($plan, $manifest);

        $this->line(sprintf(
            '  status=%s  ir_pages=%d  failures=%d',
            $irResult->status->value,
            $irResult->pages->count(),
            $irResult->failures->count(),
        ));
        $this->line(sprintf(
            '  brief_deriver_calls=%d  designer_calls=%d',
            $briefAgent->calls,
            $designerAgent->calls,
        ));

        if ($designerAgent->calls > 0) {
            $this->newLine();
            $this->info('=== Per-chunk dispatch (verifies partitioning) ===');
            foreach ($designerAgent->allSeen as $input) {
                $this->line(sprintf(
                    '  chunk %d/%d : %d pages',
                    $input->chunk_index + 1,
                    $input->total_chunks,
                    $input->chunk_pages->count(),
                ));
            }
        }

        // --- Reconciliation tie-out ---
        $this->newLine();
        $this->info('=== Reconciliation tie-out ===');
        $expectedSlugs = [];
        foreach ($plan->kept_pages->items() as $p) {
            /** @var InventoryPage $p */
            if ($p->kind === 'page' && ($ledgerByTarget[$this->targetOf($p)] ?? null)?->action === DecisionAction::Keep) {
                $expectedSlugs[] = \App\Services\Generate\PageSlug::of($p);
            }
        }
        sort($expectedSlugs);

        $actualSlugs = [];
        foreach ($irResult->pages->items() as $ir) {
            $actualSlugs[] = $ir->page_slug;
        }
        foreach ($irResult->failures->items() as $f) {
            /** @var \App\Data\IrPassFailure $f */
            // Skip the sentinel — it's not a real page slug.
            if ($f->page_slug === \App\Services\Generate\IrPass::BRIEF_FAILURE_SLUG) {
                continue;
            }
            $actualSlugs[] = $f->page_slug;
        }
        sort($actualSlugs);

        $missing = array_diff($expectedSlugs, $actualSlugs);
        $extra = array_diff($actualSlugs, $expectedSlugs);
        $duplicates = array_diff_key($actualSlugs, array_unique($actualSlugs));

        $this->line(sprintf('  expected (keep+kind=page) : %d', count($expectedSlugs)));
        $this->line(sprintf('  actual (pages+failures)   : %d', count($actualSlugs)));
        $this->line(sprintf('  missing                   : %d', count($missing)));
        $this->line(sprintf('  extra                     : %d', count($extra)));
        $this->line(sprintf('  duplicate slugs           : %d', count($duplicates)));

        if (count($missing) === 0 && count($extra) === 0 && count($duplicates) === 0) {
            $this->newLine();
            $this->info('✓ Reconciliation clean: every keep+kind=page is in pages OR failures, exactly once.');
        } else {
            $this->newLine();
            $this->error('✗ Reconciliation FAILED — see counts above.');
            if (count($missing) > 0) {
                $this->line('  missing slugs: '.implode(', ', $missing));
            }
            if (count($extra) > 0) {
                $this->line('  extra slugs: '.implode(', ', $extra));
            }
        }

        return self::SUCCESS;
    }

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
}
