<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\OrgType;
use App\Services\ContractEmitter\ContractPayloadEmitter;
use App\Services\Generate\Assembler;
use App\Services\Generate\AssetUrlRewriter;
use App\Services\Generate\BlockFill;
use App\Services\Generate\BlockFillAgent;
use App\Services\Generate\DraftLanding;
use App\Services\Generate\GalleryFiller;
use App\Services\Generate\HeroImageResolver;
use App\Services\Generate\IrPass;
use App\Services\Generate\PlatformBlockRenderer;
use App\Services\Generate\SePlatformBlockScrubber;
use App\Services\Plan\ClassifierAgent;
use App\Services\Plan\Planner;
use Illuminate\Console\Command;
use Tests\Support\Generate\FakeBlockFillAgent;
use Tests\Support\Plan\FakeClassifierAgent;
use Tests\Support\Plan\RealManifests;

// Runs the live-shaped pipeline against the offline tbirdhoops
// fixture (no LLM, no network), emits a contract envelope, and
// compares it against the fixture envelope produced by
// engine:emit-contract-fixture (which uses the same offline stack
// but the SNAPSHOTED ConversionResult from the disk fixture).
//
// The user's ask verbatim: "I want to know where the live path
// diverges from fixture replay before we rely on either." And:
// "Expect the numbers to differ; I want them explained, not matched."
//
// This isn't a live conversion (no Sonnet / Firecrawl / Anthropic
// budget spent). It's the same offline pipeline shape the
// ChainEqualsInline test runs — enough to compare the "path
// through code" without paying LLM costs. When engineering signs
// off on this offline-vs-fixture reconciliation, the live path
// (real Sonnet / Firecrawl) can be exercised separately with
// budget.
final class CompareLiveVsFixtureEnvelope extends Command
{
    protected $signature = 'engine:compare-live-vs-fixture-envelope';

    protected $description = 'Offline pipeline envelope vs fixture-replay envelope — where do the two diverge?';

    public function handle(ContractPayloadEmitter $emitter): int
    {
        $this->line('Loading fixture envelope from storage/app/public/preview/tbirdhoops-contract.json...');
        $fixturePath = storage_path('app/public/preview/tbirdhoops-contract.json');
        if (! is_file($fixturePath)) {
            $this->error('Fixture envelope missing; run engine:emit-contract-fixture first.');

            return self::FAILURE;
        }
        $fixtureData = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($fixtureData, 'Fixture must be a JSON object.');

        $this->line('Running offline pipeline (RealManifests::tbirdhoops + FakeBlockFillAgent + FakeClassifierAgent)...');
        // Swap in fakes for classifier + block-fill so no LLM calls.
        $this->getLaravel()->instance(BlockFillAgent::class, new FakeBlockFillAgent);
        $this->getLaravel()->instance(ClassifierAgent::class, new FakeClassifierAgent);

        $manifest = RealManifests::tbirdhoops();
        $plan = $this->getLaravel()->make(Planner::class)->plan($manifest);
        $irPass = $this->getLaravel()->make(IrPass::class)->run($plan, $manifest);
        $blockFillResult = $this->getLaravel()->make(BlockFill::class)->run($irPass, $plan, $manifest, 'conv-live-cmp');
        $assembly = $this->getLaravel()->make(Assembler::class)->run($blockFillResult);
        $assembly = $this->getLaravel()->make(SePlatformBlockScrubber::class)->run($assembly);
        $assembly = $this->getLaravel()->make(GalleryFiller::class)->run($assembly, []);
        $assembly = $this->getLaravel()->make(HeroImageResolver::class)->run($assembly, []);
        $assembly = $this->getLaravel()->make(AssetUrlRewriter::class)->run($assembly, $manifest);
        $platform = $this->getLaravel()->make(PlatformBlockRenderer::class)->run($plan, $manifest);
        $conversion = $this->getLaravel()->make(DraftLanding::class)->run(
            conversionId: 'conv-live-cmp',
            plan: $plan,
            assembly: $assembly,
            platform: $platform,
            manifest: $manifest,
        );

        $emit = $emitter->emit($conversion, OrgType::Club);
        $liveData = $emit->envelope->toArray();

        $this->line('');
        $this->info('=== LIVE (offline pipeline) vs FIXTURE (committed snapshot) ===');
        $this->reportShape('fixture', $fixtureData);
        $this->reportShape('live   ', $liveData);
        $this->line('');
        $this->line('DIAGNOSTIC-CODE HISTOGRAMS (see divergence):');
        $this->line(sprintf('  fixture: %s', json_encode($this->codeHistogram($fixtureData), JSON_PRETTY_PRINT)));
        $this->line(sprintf('  live:    %s', json_encode($this->codeHistogram($liveData), JSON_PRETTY_PRINT)));
        $this->line('');
        $this->line('LIVE VALIDATION VERDICT:');
        $this->line(sprintf('  errors: %d, warnings: %d', count($emit->errors), count($emit->warnings)));
        if ($emit->errors !== []) {
            foreach ($emit->errors as $e) {
                $this->warn(sprintf('  %s: %s', $e->code, $e->message));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function reportShape(string $label, array $envelope): void
    {
        $pages = is_array($envelope['pages'] ?? null) ? $envelope['pages'] : [];
        $assets = is_array($envelope['assets'] ?? null) ? $envelope['assets'] : [];
        $diagnostics = is_array($envelope['diagnostics'] ?? null) ? $envelope['diagnostics'] : [];
        $blocks = $this->countBlocksDeep($pages);
        $site = is_array($envelope['site'] ?? null) ? $envelope['site'] : [];
        $this->line(sprintf(
            '  %s: pages=%d, blocks=%d, assets=%d, diagnostics=%d, primaryColor=%s, neutralColor=%s',
            $label,
            count($pages),
            $blocks,
            count($assets),
            count($diagnostics),
            $site['primaryColor'] ?? '(none)',
            $site['neutralColor'] ?? '(none)',
        ));
    }

    /**
     * @param  array<int, mixed>  $pages
     */
    private function countBlocksDeep(array $pages): int
    {
        $count = 0;
        foreach ($pages as $p) {
            if (! is_array($p)) {
                continue;
            }
            $content = $p['data']['content'] ?? [];
            if (! is_array($content)) {
                continue;
            }
            $count += $this->countBlocksDeepInList($content);
        }

        return $count;
    }

    /**
     * @param  array<int, mixed>  $blocks
     */
    private function countBlocksDeepInList(array $blocks): int
    {
        $count = 0;
        foreach ($blocks as $b) {
            if (! is_array($b) || ! is_string($b['type'] ?? null)) {
                continue;
            }
            $count++;
            $props = is_array($b['props'] ?? null) ? $b['props'] : [];
            foreach ($props as $value) {
                if (! is_array($value)) {
                    continue;
                }
                $count += $this->countBlocksDeepInList($value);
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, int>
     */
    private function codeHistogram(array $envelope): array
    {
        $hist = [];
        $diagnostics = is_array($envelope['diagnostics'] ?? null) ? $envelope['diagnostics'] : [];
        foreach ($diagnostics as $d) {
            if (! is_array($d)) {
                continue;
            }
            $code = is_string($d['code'] ?? null) ? $d['code'] : '(unknown)';
            $hist[$code] = ($hist[$code] ?? 0) + 1;
        }

        return $hist;
    }

    private function assertIsArray(mixed $v, string $msg): void
    {
        if (! is_array($v)) {
            throw new \RuntimeException($msg);
        }
    }
}
