<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Extract\BrandExtractor;
use App\Services\Extract\HttpHtmlFetcher;
use App\Services\Extract\HttpRootNavFetcher;
use App\Services\Extract\LocalDiskFirecrawlClient;
use App\Services\Extract\S3AssetUploader;
use App\Services\Extract\SeCdnRehoster;
use App\Services\Extract\SportNginExtractor;
use App\Services\Generate\ContentLoader;
use App\Services\Generate\IrPass;
use App\Services\Plan\AnthropicClassifierAgent;
use App\Services\Plan\RootNavPlanner;
use App\Services\Plan\SePlatformContentDetector;
use Illuminate\Console\Command;
use Throwable;

// DIAGNOSTIC — runs INGEST (disk-Firecrawl) + PLAN (REAL Haiku) +
// IR-pass (REAL Opus) and stops. NO block-fill (deliberately, to keep
// the per-call cost down for targeted gateway-path experiments).
//
// Used to exercise IR-pass + classifier independently for the native-
// output_config experiment without burning Sonnet calls.
final class TestNativePath extends Command
{
    protected $signature = 'engine:test-native-path {url} {--org-id=}';

    protected $description = 'Run INGEST (disk) + PLAN (real Haiku) + IR-pass (real Opus). NO block-fill. For targeted gateway-path experiments.';

    public function handle(IrPass $irPass): int
    {
        $url = (string) $this->argument('url');
        $orgId = (string) ($this->option('org-id') ?? '');
        if ($orgId === '') {
            $this->error('--org-id is required');

            return self::FAILURE;
        }

        $this->info('=== INGEST (disk Firecrawl, real rootnav) ===');
        $html = $this->getLaravel()->make(HttpHtmlFetcher::class);
        $nav = $this->getLaravel()->make(HttpRootNavFetcher::class);
        $uploader = new S3AssetUploader(disk: 'local');
        $firecrawl = new LocalDiskFirecrawlClient(orgId: $orgId, disk: 'local');
        $extractor = new SportNginExtractor($html, $nav, $firecrawl, $uploader, new BrandExtractor, new SeCdnRehoster($uploader));

        try {
            $manifest = $extractor->extract($url);
        } catch (Throwable $e) {
            $this->error("INGEST failed: {$e->getMessage()}");

            return self::FAILURE;
        }
        $this->line(sprintf('  content_refs=%d  asset_refs=%d  flags=%d', $manifest->content_refs->count(), $manifest->asset_refs->count(), count($manifest->flags)));

        $this->info('=== PLAN (REAL Anthropic Haiku) ===');
        // Use the real classifier agent (NOT the FakeClassifier) so we
        // exercise Haiku under whatever transport path the gateway is
        // currently configured for.
        $classifier = $this->getLaravel()->make(AnthropicClassifierAgent::class);
        $planner = new RootNavPlanner($classifier, new ContentLoader(disk: 'local'), new SePlatformContentDetector);
        try {
            $plan = $planner->plan($manifest);
        } catch (Throwable $e) {
            $this->error("PLAN failed: {$e->getMessage()}");

            return self::FAILURE;
        }
        $this->line(sprintf('  kept_pages=%d  nav=%d  ledger=%d', $plan->kept_pages->count(), $plan->nav->count(), $plan->ledger->entries->count()));

        $this->info('=== IR PASS (REAL Anthropic Opus) ===');
        try {
            $irResult = $irPass->run($plan, $manifest);
        } catch (Throwable $e) {
            $this->error("IR PASS failed: {$e->getMessage()}");

            return self::FAILURE;
        }
        $this->line(sprintf('  status=%s  ir_pages=%d  failures=%d', $irResult->status->value, $irResult->pages->count(), $irResult->failures->count()));
        $this->line('  style_brief.brand_voice (excerpt): '.substr($irResult->style_brief->brand_voice, 0, 140));
        $this->line('  style_brief.palette: '.json_encode($irResult->style_brief->palette));
        $this->line('  style_brief.layout_conventions: '.count($irResult->style_brief->layout_conventions).' entries');
        $this->newLine();

        $this->info('=== IR pages produced ===');
        foreach ($irResult->pages->items() as $ir) {
            $this->line(sprintf('  %-22s  blocks=%-2d  %s', $ir->page_slug, $ir->blocks->count(), $ir->page_title));
        }
        if ($irResult->failures->count() > 0) {
            $this->newLine();
            $this->warn('=== IR pass failures ===');
            foreach ($irResult->failures->items() as $f) {
                $this->line("  {$f->page_slug}: {$f->reason}");
            }
        }

        return self::SUCCESS;
    }
}
