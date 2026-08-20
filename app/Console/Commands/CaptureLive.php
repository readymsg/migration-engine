<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\BlockFillResult;
use App\Data\ConversionResult;
use App\Services\Extract\Extractor;
use App\Services\Generate\Assembler;
use App\Services\Generate\BlockFill;
use App\Services\Generate\DraftLanding;
use App\Services\Generate\IrPass;
use App\Services\Generate\PlatformBlockRenderer;
use App\Services\Generate\SePlatformBlockScrubber;
use App\Services\Plan\Planner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Tests\Support\Generate\RecordingProductClient;
use Throwable;

// DIAGNOSTIC TOOLING (calibration runs). Drives the full live pipeline
// against an arbitrary SE URL — real Firecrawl scrape, real Opus IR
// pass, real Sonnet block-fill — and writes both a durable BlockFillResult
// fixture (replay anchor) AND a preview ConversionResult JSON. Cost:
// 1 Opus call + N Sonnet calls (where N = keep-content pages) + Firecrawl
// scrapes per content page.
//
// Uses the RecordingProductClient (test support) instead of the real
// product binding so calibration runs never accidentally hit the product
// API. Same posture as DraftLandingFixtureReplayTest: every aspect of
// the pipeline runs, only the final POST is intercepted.
//
// Same throwaway/dev-only posture as engine:emit-preview-fixture — uses
// Tests\Support helpers and assumes autoload-dev is loaded.
final class CaptureLive extends Command
{
    protected $signature = 'engine:capture-live {url} {--slug=} {--blockfill-out=} {--preview-out=}';

    protected $description = 'Run the full live pipeline against a URL: real Firecrawl + Opus + Sonnet. Writes BlockFillResult fixture + preview ConversionResult.';

    public function handle(
        Extractor $extractor,
        Planner $planner,
        IrPass $irPass,
        BlockFill $blockFill,
        Assembler $assembler,
        PlatformBlockRenderer $platformRenderer,
        SePlatformBlockScrubber $scrubber,
    ): int {
        $url = (string) $this->argument('url');
        $slug = (string) ($this->option('slug') ?? $this->deriveSlug($url));
        $blockfillOut = (string) ($this->option('blockfill-out')
            ?? base_path("tests/Fixtures/blockfill/{$slug}.json"));
        $previewOut = (string) ($this->option('preview-out')
            ?? storage_path("app/public/preview/{$slug}.json"));

        $this->info('=== Live capture ===');
        $this->line("url            : {$url}");
        $this->line("slug           : {$slug}");
        $this->line("blockfill_out  : {$blockfillOut}");
        $this->line("preview_out    : {$previewOut}");
        $this->line('queue          : '.config('queue.default'));
        $this->newLine();

        // --- 1. INGEST ---------------------------------------------------
        $started = microtime(true);
        $this->info('[1/5] INGEST (real Firecrawl + HTTP)...');
        try {
            $manifest = $extractor->extract($url);
        } catch (Throwable $e) {
            $this->error("INGEST failed: {$e->getMessage()}");

            return self::FAILURE;
        }
        $this->line(sprintf(
            '       org_id=%s  source_url=%s  content_refs=%d  asset_refs=%d  cdn_found=%d  cdn_rehosted=%d  flags=%d',
            $manifest->org_id,
            $manifest->source_url,
            $manifest->content_refs->count(),
            $manifest->asset_refs->count(),
            $manifest->cdn_assets_found,
            $manifest->cdn_assets_rehosted,
            count($manifest->flags),
        ));
        if ($manifest->flags !== []) {
            $this->line('       flags: '.implode(', ', $manifest->flags));
        }
        if ($manifest->content_failures !== null && $manifest->content_failures->count() > 0) {
            $this->line(sprintf('       content_failures=%d', $manifest->content_failures->count()));
        }

        // --- 2. PLAN -----------------------------------------------------
        $this->info('[2/5] PLAN (real Haiku for ambiguous pages)...');
        try {
            $plan = $planner->plan($manifest);
        } catch (Throwable $e) {
            $this->error("PLAN failed: {$e->getMessage()}");
            $this->writePartial($blockfillOut, ['stage' => 'plan', 'error' => $e->getMessage()]);

            return self::FAILURE;
        }
        $this->line(sprintf(
            '       kept_pages=%d  nav=%d  ledger=%d',
            $plan->kept_pages->count(),
            $plan->nav->count(),
            $plan->ledger->entries->count(),
        ));

        // --- 3. IR pass --------------------------------------------------
        $this->info('[3/5] IR PASS (real Opus, ~1 call)...');
        try {
            $irResult = $irPass->run($plan, $manifest);
        } catch (Throwable $e) {
            $this->error("IR PASS failed: {$e->getMessage()}");
            $this->writePartial($blockfillOut, ['stage' => 'ir_pass', 'error' => $e->getMessage()]);

            return self::FAILURE;
        }
        $this->line(sprintf(
            '       status=%s  ir_pages=%d  failures=%d',
            $irResult->status->value,
            $irResult->pages->count(),
            $irResult->failures->count(),
        ));

        // --- 4. Block-fill -----------------------------------------------
        $this->info('[4/5] BLOCK-FILL (real Sonnet, N calls)...');
        $conversionId = (string) Str::ulid();
        try {
            $bfResult = $blockFill->run($irResult, $plan, $manifest, $conversionId);
        } catch (Throwable $e) {
            $this->error("BLOCK-FILL failed: {$e->getMessage()}");
            $this->writePartial($blockfillOut, ['stage' => 'block_fill', 'error' => $e->getMessage()]);

            return self::FAILURE;
        }
        $this->line(sprintf(
            '       status=%s  pages=%d  failures=%d',
            $bfResult->status->value,
            $bfResult->pages->count(),
            $bfResult->failures->count(),
        ));

        // Write the durable BlockFillResult fixture BEFORE running
        // deterministic downstream stages — so if any of those throw, the
        // expensive LLM output is already safely on disk.
        $this->writeFixture($blockfillOut, $bfResult->toArray());
        $this->line("       wrote {$blockfillOut}");

        // --- 5. Assemble + platform-render + draft-landing ---------------
        $this->info('[5/5] ASSEMBLE → PLATFORM-RENDER → DRAFT-LAND (deterministic; RecordingProductClient)...');
        try {
            $assembly = $assembler->run($bfResult);
            $this->line(sprintf(
                '       assembly.status=%s  pages=%d  failures=%d  block_issues=%d page(s)',
                $assembly->status->value,
                $assembly->pages->count(),
                $assembly->failures->count(),
                count($assembly->block_issues_by_slug),
            ));

            // Deterministic SE-platform block scrub — drops SE-promo
            // buttons/cards + stale live-widget countdowns block-fill
            // faithfully rendered from the scraped body. Sidecar
            // scrub_issues_by_slug is threaded through DraftLanding into
            // ConversionResult so the audit trail lands in the preview
            // fixture. See CLAUDE.md "GENERATE — SE-platform block
            // scrubber". Step-6's trigger-endpoint chain inserts the
            // scrubber at the same position: Assembler → Scrubber →
            // Platform → DraftLanding.
            $assembly = $scrubber->run($assembly);
            $scrubbedPageCount = count($assembly->scrub_issues_by_slug);
            $scrubbedBlockCount = array_sum(array_map(
                'count',
                $assembly->scrub_issues_by_slug,
            ));
            $this->line(sprintf(
                '       scrub.pages_touched=%d  blocks_scrubbed=%d',
                $scrubbedPageCount,
                $scrubbedBlockCount,
            ));

            $platform = $platformRenderer->run($plan, $manifest);
            $this->line(sprintf(
                '       platform.status=%s  pages=%d  failures=%d',
                $platform->status->value,
                $platform->pages->count(),
                $platform->failures->count(),
            ));

            $client = new RecordingProductClient;
            $client->returns("DRAFT_{$slug}", "https://teamlinkt.test/drafts/{$slug}");
            $lander = new DraftLanding($client);
            $conversion = $lander->run(
                conversionId: "live-{$slug}",
                plan: $plan,
                assembly: $assembly,
                platform: $platform,
                manifest: $manifest,
            );

            $this->line(sprintf(
                '       conversion.status=%s  page_map=%d  nav=%d  failures=%d',
                $conversion->status->value,
                count($conversion->page_map),
                $conversion->nav->count(),
                $conversion->failures->count(),
            ));

            $this->writeFixture($previewOut, $conversion->toArray());
            $this->line("       wrote {$previewOut}");
        } catch (Throwable $e) {
            $this->error("Downstream (deterministic) failed: {$e->getMessage()}");
            $this->line('       BlockFillResult fixture was written; downstream can be replayed once the bug is fixed');

            return self::FAILURE;
        }

        $elapsed = number_format(microtime(true) - $started, 1);
        $this->newLine();
        $this->info("Done in {$elapsed}s. Browse: http://127.0.0.1:8000/preview/{$slug}");

        return self::SUCCESS;
    }

    private function deriveSlug(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $host = preg_replace('/^www\./i', '', $host) ?? '';
        $host = (string) preg_replace('/\.[a-z]+$/i', '', $host);

        return Str::slug($host !== '' ? $host : 'site');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeFixture(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create directory: {$dir}");
        }
        try {
            $json = json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new RuntimeException("Could not encode payload: {$e->getMessage()}");
        }
        if (file_put_contents($path, $json.PHP_EOL) === false) {
            throw new RuntimeException("Could not write: {$path}");
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writePartial(string $path, array $payload): void
    {
        $partial = preg_replace('/\.json$/', '.partial.json', $path) ?? "{$path}.partial.json";
        $this->writeFixture($partial, $payload);
        $this->line("       wrote partial-failure marker {$partial}");
    }
}
