<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\BlockFillResult;
use App\Data\ConversionResult;
use App\Services\Generate\Assembler;
use App\Services\Generate\BlockCoercer;
use App\Services\Generate\ContentLoader;
use App\Services\Generate\DraftLanding;
use App\Services\Generate\PlatformBlockRenderer;
use App\Services\Plan\RootNavPlanner;
use App\Services\Plan\SePlatformContentDetector;
use App\Services\Schema\DefaultPuckComponentSchema;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Tests\Support\Generate\RecordingProductClient;
use Tests\Support\Plan\FakeClassifierAgent;
use Tests\Support\Plan\RealManifests;

// THROWAWAY (BUILD.md step 7). Emits the ConversionResult JSON the
// preview bundle renders against, by replaying the exact pipeline
// DraftLandingFixtureReplayTest exercises:
//
//   real Manifest (tbirdhoops rootnav fixtures, no HTTP)
//     → PLAN (FakeClassifierAgent — deterministic, offline)
//   tbirdhoops BlockFillResult fixture (tests/Fixtures/blockfill/)
//     → Assembler
//   SitePlan + Manifest
//     → PlatformBlockRenderer
//   all of the above
//     → DraftLanding (RecordingProductClient — no real product call)
//   → ConversionResult JSON → storage/app/public/preview/<slug>.json
//
// NO LLM. NO network. Cost: 0. Determinism: full. Re-run any time the
// upstream BlockFillResult fixture or any deterministic stage changes;
// the bundle's input shape stays stable (mirrors the eventual
// GET /api/demo/{id}/site that step 6 will produce).
//
// Dev-only: depends on Tests\Support helpers (autoload-dev). Not
// available under composer install --no-dev. Same posture as the
// throwaway Demo/Preview namespace — deleted at graduation alongside
// the rest of the preview slice.
final class EmitPreviewFixture extends Command
{
    protected $signature = 'engine:emit-preview-fixture {--out= : Output path (default: storage/app/public/preview/tbirdhoops.json)}';

    protected $description = 'Replay the deterministic pipeline and write a ConversionResult JSON the preview bundle consumes.';

    public function handle(): int
    {
        $output = (string) ($this->option('out')
            ?? storage_path('app/public/preview/tbirdhoops.json'));

        $this->info('=== Preview fixture emit ===');
        $this->line("output : {$output}");
        $this->line('source : tbirdhoops rootnav fixtures + tests/Fixtures/blockfill/tbirdhoops.json');
        $this->line('cost   : 0 (deterministic; no LLM, no network)');
        $this->newLine();

        $manifest = RealManifests::tbirdhoops();
        $this->line(sprintf(
            '[1/5] Manifest: content_refs=%d  asset_refs=%d',
            $manifest->content_refs->count(),
            $manifest->asset_refs->count(),
        ));

        $schema = new DefaultPuckComponentSchema;
        $planner = new RootNavPlanner(
            new FakeClassifierAgent,
            new ContentLoader(disk: 'local'),
            new SePlatformContentDetector,
        );
        $plan = $planner->plan($manifest);
        $this->line(sprintf(
            '[2/5] PLAN:     kept_pages=%d  nav=%d  ledger=%d',
            $plan->kept_pages->count(),
            $plan->nav->count(),
            $plan->ledger->entries->count(),
        ));

        $blockFill = $this->loadBlockFillFixture();
        $this->line(sprintf(
            '[3/5] BlockFill (fixture): pages=%d  failures=%d',
            $blockFill->pages->count(),
            $blockFill->failures->count(),
        ));

        $assembly = (new Assembler(new BlockCoercer($schema)))->run($blockFill);
        $this->line(sprintf(
            '[4/5] Assembler: status=%s  pages=%d  failures=%d',
            $assembly->status->value,
            $assembly->pages->count(),
            $assembly->failures->count(),
        ));

        $platform = (new PlatformBlockRenderer($schema))->run($plan, $manifest);
        $this->line(sprintf(
            '[5/5] Platform:  status=%s  pages=%d  failures=%d',
            $platform->status->value,
            $platform->pages->count(),
            $platform->failures->count(),
        ));

        $client = new RecordingProductClient;
        $client->returns('TBIRD_PREVIEW', 'https://teamlinkt.test/drafts/TBIRD_PREVIEW');
        $result = (new DraftLanding($client))->run(
            conversionId: 'tbirdhoops-preview',
            plan: $plan,
            assembly: $assembly,
            platform: $platform,
            manifest: $manifest,
        );

        $this->newLine();
        $this->info('=== ConversionResult ===');
        $this->line("status          : {$result->status->value}");
        $this->line('page_map keys   : '.implode(', ', array_keys($result->page_map)));
        $this->line("nav entries     : {$result->nav->count()}");
        $this->line("failures        : {$result->failures->count()}");

        $this->writeFixture($result, $output);
        $this->newLine();
        $this->info("Wrote preview fixture to {$output}");
        $this->line('Browse: php artisan serve  +  npm run dev  →  http://127.0.0.1:8000/preview/tbirdhoops');

        return self::SUCCESS;
    }

    private function loadBlockFillFixture(): BlockFillResult
    {
        $path = base_path('tests/Fixtures/blockfill/tbirdhoops.json');
        if (! is_file($path)) {
            throw new RuntimeException("BlockFillResult fixture not found: {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Could not read fixture: {$path}");
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Fixture is not valid JSON: {$e->getMessage()}");
        }

        return BlockFillResult::from($decoded);
    }

    private function writeFixture(ConversionResult $result, string $output): void
    {
        $dir = dirname($output);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create directory: {$dir}");
        }

        try {
            $json = json_encode(
                $result->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new RuntimeException("Could not encode ConversionResult: {$e->getMessage()}");
        }
        if (file_put_contents($output, $json.PHP_EOL) === false) {
            throw new RuntimeException("Could not write fixture: {$output}");
        }
    }
}
