<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\BlockFillResult;
use App\Data\Manifest;
use App\Services\Extract\BrandExtractor;
use App\Services\Extract\FirecrawlClient;
use App\Services\Extract\HttpHtmlFetcher;
use App\Services\Extract\HttpRootNavFetcher;
use App\Services\Extract\LocalDiskFirecrawlClient;
use App\Services\Extract\S3AssetUploader;
use App\Services\Extract\SeCdnRehoster;
use App\Services\Extract\SportNginExtractor;
use App\Services\Generate\BlockFill;
use App\Services\Generate\IrPass;
use App\Services\Plan\Planner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

// One-time durable-fixture capture for the tbirdhoops site. Runs the
// REAL Opus IrPass + REAL Sonnet block-fill against the on-disk
// captured tbirdhoops scrapes (no Firecrawl call) and serializes the
// resulting BlockFillResult to a version-controlled JSON fixture that
// every downstream slice (assembler, draft-landing, preview, SCORE
// & LOG) can replay against without re-spending LLM credits.
//
// COST: ~1 Opus call (IR pass) + ~7 Sonnet calls (block-fill). No
// Firecrawl call — scrapes are served from
// storage/app/private/orgs/ngin-63620/scrapes/. The SE rootNav API
// (`/page/nav/<id>`) IS called live (free + public + no auth).
//
// Re-run this command to regenerate the fixture if block-fill behaviour
// changes meaningfully. Do NOT run it casually — it costs real money
// per invocation. The asserted-stable fixture is the contract: tests
// and demos READ it; this command WRITES it.
final class CaptureTbirdhoopsBlockFill extends Command
{
    protected $signature = 'engine:capture-tbirdhoops-block-fill {--out= : Output path (default: tests/Fixtures/blockfill/tbirdhoops.json)}';

    protected $description = 'Run real IR-pass + block-fill against captured tbirdhoops bodies and save the BlockFillResult as a durable test fixture.';

    public function handle(Planner $planner, IrPass $irPass, BlockFill $blockFill): int
    {
        $orgId = 'ngin-63620';
        $sourceUrl = 'https://www.tbirdhoops.org/';
        $output = (string) ($this->option('out') ?? base_path('tests/Fixtures/blockfill/tbirdhoops.json'));

        $this->info('=== Tbirdhoops BlockFillResult capture ===');
        $this->line("org_id      : {$orgId}");
        $this->line("source_url  : {$sourceUrl}");
        $this->line("output      : {$output}");
        $this->line("scrapes from: storage/app/private/orgs/{$orgId}/scrapes (Firecrawl skipped)");
        $this->line('LLMs        : real Opus 4.8 (IR pass, 1 call) + real Sonnet 4.6 (block-fill, ~7 calls)');
        $this->newLine();

        // Bind FirecrawlClient to the on-disk reader so the extractor
        // doesn't call Firecrawl. The rootNav fetcher and HTML fetcher
        // are left as the production HTTP impls (SE API is free).
        $this->getLaravel()->instance(
            FirecrawlClient::class,
            new LocalDiskFirecrawlClient(orgId: $orgId, disk: 'local'),
        );

        $this->info('[1/4] Extracting Manifest (real SE rootNav, on-disk Firecrawl)...');
        $manifest = $this->extract($sourceUrl);
        $this->line(sprintf(
            '       content_refs=%d  asset_refs=%d  flags=%s',
            $manifest->content_refs->count(),
            $manifest->asset_refs->count(),
            $manifest->flags === [] ? '[]' : implode(',', $manifest->flags),
        ));

        $this->info('[2/4] PLAN...');
        $plan = $planner->plan($manifest);
        $this->line(sprintf(
            '       kept_pages=%d  nav=%d  ledger_entries=%d',
            $plan->kept_pages->count(),
            $plan->nav->count(),
            $plan->ledger->entries->count(),
        ));

        $this->info('[3/4] IR pass (real Opus)...');
        $irResult = $irPass->run($plan, $manifest);
        $this->line(sprintf(
            '       status=%s  ir_pages=%d  failures=%d',
            $irResult->status->value,
            $irResult->pages->count(),
            $irResult->failures->count(),
        ));

        $this->info('[4/4] Block-fill (real Sonnet)...');
        $conversionId = (string) Str::ulid();
        $bfResult = $blockFill->run($irResult, $plan, $manifest, $conversionId);
        $this->line(sprintf(
            '       status=%s  pages=%d  failures=%d',
            $bfResult->status->value,
            $bfResult->pages->count(),
            $bfResult->failures->count(),
        ));

        $this->writeFixture($bfResult, $output);
        $this->newLine();
        $this->info("✅ Wrote BlockFillResult fixture to {$output}");

        return self::SUCCESS;
    }

    private function extract(string $sourceUrl): Manifest
    {
        $extractor = new SportNginExtractor(
            $this->getLaravel()->make(HttpHtmlFetcher::class),
            $this->getLaravel()->make(HttpRootNavFetcher::class),
            $this->getLaravel()->make(FirecrawlClient::class),
            new S3AssetUploader(disk: (string) config('services.scrapes.disk', 'local')),
            new BrandExtractor,
            new SeCdnRehoster(new S3AssetUploader(disk: (string) config('services.scrapes.disk', 'local'))),
        );

        return $extractor->extract($sourceUrl);
    }

    private function writeFixture(BlockFillResult $result, string $output): void
    {
        $dir = dirname($output);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create directory: {$dir}");
        }

        // Pretty-print so a reviewer can read the fixture in a PR diff.
        $json = json_encode(
            $result->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        if (file_put_contents($output, $json.PHP_EOL) === false) {
            throw new RuntimeException("Could not write fixture: {$output}");
        }
    }
}
