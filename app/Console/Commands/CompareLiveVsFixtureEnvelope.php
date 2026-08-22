<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\OrgType;
use App\Services\ContractEmitter\ContractEnvelopeStore;
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

// Compares a live-path OR offline-path contract envelope against the
// fixture envelope on disk. Two modes:
//
//   --from-live=<conversionId>  → read envelope from
//                                 ContractEnvelopeStore (real
//                                 conversion; first-ever real vs
//                                 fixture comparison).
//   (default)                    → run the OFFLINE pipeline
//                                 (RealManifests + fake agents;
//                                 useful for CI, both sides are
//                                 offline).
//
// The user's ask on this tool verbatim: "the offline comparison you
// already ran compared offline-with-fake-scraper against the
// fixture — both offline. Neither side exercised the live path. So
// --from-live is the first real comparison, and the fixture is the
// only baseline we have."
//
// HEURISTIC-FITTED SIGNAL DETECTION. TeamMembers + Sponsors + Grid
// detection was tuned against ONE fixture's Card shapes. If live
// Sonnet emits different Card layouts, the folds silently stop
// firing — Board becomes 10 Text[h3]-Image-Text[p] triples instead
// of one TeamMembers widget. The command surfaces this explicitly:
//   1. Widget-fold count per type (fixture vs live).
//   2. Text[h3] count (proxy for "Card that didn't fold").
//   3. Explicit ⚠️ line when fixture has ≥3 widget-folds of a type
//      and live has 0 of that type — near-certain signal that
//      the heuristic didn't fire on live Card shapes.
//
// Report intent per the user: "Do not tune anything to make the
// numbers match — I want the real divergence."
final class CompareLiveVsFixtureEnvelope extends Command
{
    protected $signature = 'engine:compare-live-vs-fixture-envelope
        {--from-live= : Compare a live conversion envelope from ContractEnvelopeStore (skips the offline pipeline). Value is the conversion_id.}';

    protected $description = 'Live-or-offline envelope vs fixture envelope — attribute every divergence, flag heuristic-fitted signals.';

    public function handle(
        ContractPayloadEmitter $emitter,
        ContractEnvelopeStore $envelopeStore,
    ): int {
        $fixtureData = $this->loadFixture();
        if ($fixtureData === null) {
            return self::FAILURE;
        }

        $liveConversionId = $this->option('from-live');
        if (is_string($liveConversionId) && $liveConversionId !== '') {
            [$liveData, $liveErrors, $liveWarnings, $source] = $this->loadLiveEnvelope($envelopeStore, $liveConversionId);
        } else {
            [$liveData, $liveErrors, $liveWarnings, $source] = $this->runOfflinePipeline($emitter);
        }
        if ($liveData === null) {
            return self::FAILURE;
        }

        $this->line('');
        $this->info(sprintf('=== %s vs FIXTURE (committed snapshot) ===', $source));
        $this->reportShape('fixture', $fixtureData);
        $this->reportShape('live   ', $liveData);

        $this->line('');
        $this->info('BLOCK-TYPE HISTOGRAM (deep, incl. slot children):');
        $fixtureHist = $this->blockHistogram($fixtureData);
        $liveHist = $this->blockHistogram($liveData);
        $this->line(sprintf('  fixture: %s', $this->formatHist($fixtureHist)));
        $this->line(sprintf('  live:    %s', $this->formatHist($liveHist)));

        $this->line('');
        $this->info('ASSETS + TOKEN RECONCILIATION:');
        $this->reportAssets('fixture', $fixtureData);
        $this->reportAssets('live   ', $liveData);

        $this->line('');
        $this->info('PALETTE (SiteSettingsEmitter output):');
        $this->reportPalette('fixture', $fixtureData);
        $this->reportPalette('live   ', $liveData);

        $this->line('');
        $this->info('DIAGNOSTIC-CODE HISTOGRAMS:');
        $fixtureCodes = $this->codeHistogram($fixtureData);
        $liveCodes = $this->codeHistogram($liveData);
        $this->line(sprintf('  fixture: %s', $this->formatHist($fixtureCodes)));
        $this->line(sprintf('  live:    %s', $this->formatHist($liveCodes)));

        $this->line('');
        $this->info('CODES FIRED ON LIVE BUT NOT ON FIXTURE:');
        $liveOnly = array_diff_key($liveCodes, $fixtureCodes);
        if ($liveOnly === []) {
            $this->line('  (none)');
        } else {
            foreach ($liveOnly as $code => $count) {
                $this->line(sprintf('  %s: %d', $code, $count));
            }
        }

        $this->line('');
        $this->info('LIVE VALIDATION VERDICT:');
        $this->line(sprintf('  errors: %d, warnings: %d', count($liveErrors), count($liveWarnings)));
        foreach ($liveErrors as $e) {
            $this->line(sprintf('  <fg=yellow>ERROR</> %s: %s', $e['code'] ?? '?', $e['message'] ?? '?'));
        }

        $this->line('');
        $this->info('HEURISTIC-FITTED SIGNAL CHECK:');
        $this->reportHeuristicSignals($fixtureHist, $fixtureData, $liveHist, $liveData);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadFixture(): ?array
    {
        $this->line('Loading fixture envelope: storage/app/public/preview/tbirdhoops-contract.json');
        $fixturePath = storage_path('app/public/preview/tbirdhoops-contract.json');
        if (! is_file($fixturePath)) {
            $this->error('Fixture envelope missing; run engine:emit-contract-fixture first.');

            return null;
        }
        $decoded = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            $this->error('Fixture is not a JSON object.');

            return null;
        }

        return $decoded;
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>, 3: string}
     */
    private function loadLiveEnvelope(ContractEnvelopeStore $store, string $conversionId): array
    {
        $this->line("Loading live envelope from ContractEnvelopeStore: {$conversionId}");
        $envelope = $store->get($conversionId);
        if ($envelope === null) {
            $this->error("No envelope for conversion `{$conversionId}` in ContractEnvelopeStore.");
            $this->line('Was Finalize completed? Check /api/conversions/'.$conversionId.'/status');

            return [null, [], [], 'live'];
        }

        // Validation state isn't stored on the envelope directly.
        // We can DERIVE the emitter's error+warning list by re-running
        // the envelope through the validator here — but that would
        // re-emit and might mask a real divergence. Simpler: read the
        // envelope.diagnostics that carry `block_delta_unaccounted`
        // and every `error`-severity code, and count those as errors.
        // Validation warnings (numeric range hints) aren't stored, so
        // they don't surface in the live path — that's a small gap.
        $data = $envelope->toArray();
        [$errors, $warnings] = $this->extractValidationVerdictFromDiagnostics($data);

        return [$data, $errors, $warnings, 'LIVE conversion '.$conversionId];
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>, 3: string}
     */
    private function runOfflinePipeline(ContractPayloadEmitter $emitter): array
    {
        $this->line('Running offline pipeline (RealManifests + FakeBlockFillAgent + FakeClassifierAgent)...');
        // Offline mode needs the test-namespace doubles. Autoload
        // them lazily so the class can be used in production without
        // dev deps for the --from-live path.
        if (! class_exists(FakeBlockFillAgent::class)) {
            $this->error('Offline mode requires dev deps (Tests\\Support\\* classes). Run without --from-live only on a dev box, or use --from-live=<conversionId> in production.');

            return [null, [], [], 'offline'];
        }

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
        $errors = array_map(
            static fn ($e) => ['code' => $e->code, 'message' => $e->message],
            $emit->errors,
        );
        $warnings = array_map(
            static fn ($w) => ['code' => $w->code, 'message' => $w->message],
            $emit->warnings,
        );

        return [$emit->envelope->toArray(), $errors, $warnings, 'OFFLINE pipeline (fakes)'];
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function extractValidationVerdictFromDiagnostics(array $envelope): array
    {
        $errors = [];
        $warnings = [];
        $diagnostics = is_array($envelope['diagnostics'] ?? null) ? $envelope['diagnostics'] : [];
        foreach ($diagnostics as $d) {
            if (! is_array($d)) {
                continue;
            }
            $sev = $d['severity'] ?? 'info';
            if ($sev === 'error') {
                $errors[] = $d;
            } elseif ($sev === 'warning') {
                $warnings[] = $d;
            }
        }

        return [$errors, $warnings];
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
        $this->line(sprintf(
            '  %s: pages=%d, blocks=%d (deep), assets=%d, diagnostics=%d',
            $label,
            count($pages),
            $blocks,
            count($assets),
            count($diagnostics),
        ));
        // Pages by slug so the reviewer sees the ACTUAL page set,
        // not just the count.
        $slugs = array_map(
            static fn ($p) => is_array($p) ? (string) ($p['slug'] ?? '?') : '?',
            $pages,
        );
        $slugs = array_map(fn ($s) => $s === '' ? '(home)' : $s, $slugs);
        $this->line(sprintf('    pages: [%s]', implode(', ', $slugs)));
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function reportAssets(string $label, array $envelope): void
    {
        $assets = is_array($envelope['assets'] ?? null) ? $envelope['assets'] : [];
        $pages = is_array($envelope['pages'] ?? null) ? $envelope['pages'] : [];

        // Every tl-asset:<ref> that appears anywhere in pages
        // should have a matching assets[] entry. Count both sides.
        $declaredRefs = [];
        foreach ($assets as $a) {
            if (is_array($a) && is_string($a['ref'] ?? null)) {
                $declaredRefs[$a['ref']] = false;
            }
        }
        $tokenCount = 0;
        array_walk_recursive($pages, function ($v) use (&$declaredRefs, &$tokenCount): void {
            if (is_string($v) && str_starts_with($v, 'tl-asset:')) {
                $tokenCount++;
                $ref = substr($v, strlen('tl-asset:'));
                if (isset($declaredRefs[$ref])) {
                    $declaredRefs[$ref] = true;
                }
            }
        });
        $matched = count(array_filter($declaredRefs));
        $orphaned = count($declaredRefs) - $matched;
        $this->line(sprintf(
            '  %s: %d assets declared, %d tokens used, %d matched, %d orphaned',
            $label,
            count($declaredRefs),
            $tokenCount,
            $matched,
            $orphaned,
        ));
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function reportPalette(string $label, array $envelope): void
    {
        $site = is_array($envelope['site'] ?? null) ? $envelope['site'] : [];
        $primary = $site['primaryColor'] ?? '(none)';
        $neutral = $site['neutralColor'] ?? '(none)';
        $logo = $site['logoUrl'] ?? '(none)';
        $this->line(sprintf(
            '  %s: primary=%s, neutral=%s, logo=%s',
            $label,
            $primary,
            $neutral,
            is_string($logo) && strlen($logo) > 40 ? substr($logo, 0, 40).'…' : $logo,
        ));
    }

    /**
     * Heuristic-fitted signal detection. The three fold heuristics
     * (TeamMembers / Sponsors / Locations) were tuned against
     * tbirdhoops's specific Card shapes. If live Sonnet emits
     * different shapes, the folds stop firing — Board becomes N
     * Text[h3]+Image+Text[p] triples instead of a TeamMembers
     * widget. Surface this explicitly, not buried in a histogram.
     *
     * @param  array<string, int>  $fixtureHist
     * @param  array<string, mixed>  $fixture
     * @param  array<string, int>  $liveHist
     * @param  array<string, mixed>  $live
     */
    private function reportHeuristicSignals(array $fixtureHist, array $fixture, array $liveHist, array $live): void
    {
        $foldTypes = [
            'TeamMembers' => 'people-directory fold (Board/Contacts Cards)',
            'Sponsors' => 'sponsor-deck fold (image+href Cards)',
            'Locations' => 'Google-Maps-image consolidation',
            'NewsList' => 'news-article Card deck fold',
            'Grid' => 'Columns → Grid wrapper',
        ];
        $flagged = false;
        foreach ($foldTypes as $type => $description) {
            $fixCount = $fixtureHist[$type] ?? 0;
            $liveCount = $liveHist[$type] ?? 0;
            $status = match (true) {
                $fixCount === 0 && $liveCount === 0 => 'both zero (heuristic not tested by this comparison)',
                $fixCount > 0 && $liveCount === 0 => "⚠️  FIRED IN FIXTURE ({$fixCount}×) BUT NOT ON LIVE — heuristic may not fit live Card shapes",
                $liveCount > 0 && $fixCount === 0 => "live emitted {$liveCount}× but fixture had 0 — live-only signal, investigate",
                default => "fixture={$fixCount}, live={$liveCount}",
            };
            if ($fixCount > 0 && $liveCount === 0) {
                $flagged = true;
                $this->line(sprintf('  <fg=yellow>%s</> (%s): %s', $type, $description, $status));
            } else {
                $this->line(sprintf('  %s (%s): %s', $type, $description, $status));
            }
        }

        // Proxy for "Card that didn't fold": Text[h3] count.
        // mapCard emits a Text[h3] for the Card's title when it
        // unfolds (i.e., wasn't folded into a widget). A cluster of
        // h3s is a good signal for "Cards emitted individually
        // where a fold might have absorbed them."
        $fixtureH3 = $this->textAsCount($fixture, 'h3');
        $liveH3 = $this->textAsCount($live, 'h3');
        $this->line('');
        $this->line(sprintf(
            '  Text[as=h3] blocks (proxy for unfolded Cards): fixture=%d, live=%d',
            $fixtureH3,
            $liveH3,
        ));

        // Correlate: if live has substantially MORE h3s than fixture
        // AND fewer widget folds, that's near-certain evidence that
        // heuristics missed on live shapes.
        $fixtureFolds = ($fixtureHist['TeamMembers'] ?? 0) + ($fixtureHist['Sponsors'] ?? 0) + ($fixtureHist['NewsList'] ?? 0);
        $liveFolds = ($liveHist['TeamMembers'] ?? 0) + ($liveHist['Sponsors'] ?? 0) + ($liveHist['NewsList'] ?? 0);
        if ($fixtureFolds > 0 && $liveFolds === 0 && $liveH3 > $fixtureH3 * 2) {
            $this->line('');
            $this->line('  <fg=yellow;options=bold>STRONG HEURISTIC-FITTED SIGNAL:</>');
            $this->line(sprintf(
                '  <fg=yellow>fixture had %d widget-folds (TeamMembers/Sponsors/NewsList) absorbing Cards;</>',
                $fixtureFolds,
            ));
            $this->line(sprintf(
                '  <fg=yellow>live has 0 widget-folds AND %d Text[h3] blocks vs fixture %d.</>',
                $liveH3,
                $fixtureH3,
            ));
            $this->line('  <fg=yellow>Board/Contacts pages likely emitted as unfolded Card sequences.</>');
            $this->line('  <fg=yellow>Inspect live envelope.pages[?slug=our-board].data.content for actual Card shapes.</>');
        } elseif (! $flagged) {
            $this->line('  No heuristic-fitted-fit-drop signal detected — folds fired on both sides.');
        }
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function textAsCount(array $envelope, string $as): int
    {
        $count = 0;
        $pages = is_array($envelope['pages'] ?? null) ? $envelope['pages'] : [];
        foreach ($pages as $p) {
            if (! is_array($p)) {
                continue;
            }
            $content = $p['data']['content'] ?? [];
            if (is_array($content)) {
                $count += $this->textAsCountInList($content, $as);
            }
        }

        return $count;
    }

    /**
     * @param  array<int, mixed>  $blocks
     */
    private function textAsCountInList(array $blocks, string $as): int
    {
        $count = 0;
        foreach ($blocks as $b) {
            if (! is_array($b) || ! is_string($b['type'] ?? null)) {
                continue;
            }
            if ($b['type'] === 'Text' && (($b['props']['as'] ?? '') === $as)) {
                $count++;
            }
            $props = is_array($b['props'] ?? null) ? $b['props'] : [];
            foreach ($props as $value) {
                if (is_array($value)) {
                    $count += $this->textAsCountInList($value, $as);
                }
            }
        }

        return $count;
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
    private function blockHistogram(array $envelope): array
    {
        $hist = [];
        $pages = is_array($envelope['pages'] ?? null) ? $envelope['pages'] : [];
        foreach ($pages as $p) {
            if (! is_array($p)) {
                continue;
            }
            $content = $p['data']['content'] ?? [];
            if (is_array($content)) {
                $this->accumulateBlockTypes($content, $hist);
            }
        }
        arsort($hist);

        return $hist;
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @param  array<string, int>  $hist  by-reference
     */
    private function accumulateBlockTypes(array $blocks, array &$hist): void
    {
        foreach ($blocks as $b) {
            if (! is_array($b) || ! is_string($b['type'] ?? null)) {
                continue;
            }
            $t = $b['type'];
            $hist[$t] = ($hist[$t] ?? 0) + 1;
            $props = is_array($b['props'] ?? null) ? $b['props'] : [];
            foreach ($props as $value) {
                if (is_array($value)) {
                    $this->accumulateBlockTypes($value, $hist);
                }
            }
        }
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
        arsort($hist);

        return $hist;
    }

    /**
     * @param  array<string, int>  $hist
     */
    private function formatHist(array $hist): string
    {
        if ($hist === []) {
            return '(empty)';
        }
        $parts = [];
        foreach ($hist as $k => $v) {
            $parts[] = "{$k}={$v}";
        }

        return implode(', ', $parts);
    }
}
