<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\SiteImport\Asset;
use App\Data\SiteImport\Block;
use App\Data\SiteImport\Diagnostic;
use App\Data\SiteImport\Envelope;
use App\Data\SiteImport\Page;
use App\Data\SiteImport\PageData;
use App\Data\SiteImport\SiteSettings;
use App\Data\SiteImport\Source;
use App\Services\ContractEmitter\ContractEnvelopeStore;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

// Focused tests for the --from-live path of the compare command.
// The offline path re-runs the whole pipeline; we don't re-exercise
// that here (it's covered by ChainEqualsInlineTest and the
// FinalizeEmitsContractEnvelope suite). What we DO test:
//
//   1. --from-live reads from ContractEnvelopeStore and reports
//      "LIVE conversion <id>" in output.
//   2. --from-live errors cleanly when no envelope for id.
//   3. Heuristic-fitted signal check fires when fixture has widget
//      folds AND live has zero.
//
// The user's ask: "have the compare output flag anything that
// suggests the fold heuristics are fixture-fitted. TeamMembers and
// Sponsors detection was tuned against one fixture's Card shapes;
// if live Sonnet produces different Card shapes and the folds stop
// firing, that's the finding I most want surfaced, not buried in a
// histogram diff."
final class CompareLiveVsFixtureEnvelopeTest extends TestCase
{
    #[Test]
    public function from_live_missing_envelope_errors_cleanly(): void
    {
        if (! is_file(storage_path('app/public/preview/tbirdhoops-contract.json'))) {
            $this->markTestSkipped('tbirdhoops contract fixture not present — run engine:emit-contract-fixture first.');
        }

        $exit = Artisan::call('engine:compare-live-vs-fixture-envelope', ['--from-live' => 'conv-does-not-exist']);
        $out = Artisan::output();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No envelope for conversion `conv-does-not-exist`', $out);
    }

    #[Test]
    public function from_live_reads_envelope_from_store_and_reports_live_source(): void
    {
        if (! is_file(storage_path('app/public/preview/tbirdhoops-contract.json'))) {
            $this->markTestSkipped('tbirdhoops contract fixture not present — run engine:emit-contract-fixture first.');
        }

        app(ContractEnvelopeStore::class)->put('conv-live-abc123', $this->envelopeWith(
            pages: [
                new Page(
                    id: 'home',
                    slug: '',
                    title: 'Home',
                    parentId: null,
                    navOrder: 0,
                    showInNav: true,
                    data: new PageData(content: new DataCollection(Block::class, [])),
                ),
            ],
            primaryColor: '#AE292E',
        ));

        $exit = Artisan::call('engine:compare-live-vs-fixture-envelope', ['--from-live' => 'conv-live-abc123']);
        $out = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('LIVE conversion conv-live-abc123', $out);
        $this->assertStringContainsString('primary=#AE292E', $out);
    }

    #[Test]
    public function heuristic_fitted_signal_fires_when_live_has_no_widget_folds(): void
    {
        if (! is_file(storage_path('app/public/preview/tbirdhoops-contract.json'))) {
            $this->markTestSkipped('tbirdhoops contract fixture not present — run engine:emit-contract-fixture first.');
        }
        $fixture = json_decode((string) file_get_contents(storage_path('app/public/preview/tbirdhoops-contract.json')), true);
        // The fixture is the checked-in tbirdhoops envelope. Verify it
        // still carries TeamMembers or Sponsors folds — if the fixture
        // regenerated without them, this test's premise no longer
        // holds and should be updated (not deleted).
        $hist = [];
        $this->collectBlockTypes($fixture['pages'] ?? [], $hist);
        $foldCount = ($hist['TeamMembers'] ?? 0) + ($hist['Sponsors'] ?? 0) + ($hist['NewsList'] ?? 0);
        if ($foldCount === 0) {
            $this->markTestSkipped('Fixture no longer carries TeamMembers/Sponsors/NewsList folds; test premise invalidated. Update the test.');
        }

        // Live envelope with zero widget-folds — simulates live Sonnet
        // emitting Card shapes that the heuristic didn't fold.
        app(ContractEnvelopeStore::class)->put('conv-no-folds', $this->envelopeWith(
            pages: [
                new Page(
                    id: 'home',
                    slug: '',
                    title: 'Home',
                    parentId: null,
                    navOrder: 0,
                    showInNav: true,
                    data: new PageData(content: new DataCollection(Block::class, [])),
                ),
            ],
        ));

        $exit = Artisan::call('engine:compare-live-vs-fixture-envelope', ['--from-live' => 'conv-no-folds']);
        $out = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('FIRED IN FIXTURE', $out);
        $this->assertStringContainsString('BUT NOT ON LIVE', $out);
        $this->assertStringContainsString('heuristic may not fit live Card shapes', $out);
    }

    /**
     * @param  array<int, Page>  $pages
     */
    private function envelopeWith(array $pages, string $primaryColor = '#123456'): Envelope
    {
        return new Envelope(
            schemaVersion: 1,
            source: new Source(
                url: 'https://x.com',
                scrapedAt: '2026-08-21T00:00:00Z',
                pagesDiscovered: 1,
                pagesMapped: 1,
            ),
            site: new SiteSettings(primaryColor: $primaryColor),
            pages: new DataCollection(Page::class, $pages),
            assets: new DataCollection(Asset::class, []),
            diagnostics: new DataCollection(Diagnostic::class, []),
        );
    }

    /**
     * @param  array<int, mixed>  $pages
     * @param  array<string, int>  $hist
     */
    private function collectBlockTypes(array $pages, array &$hist): void
    {
        foreach ($pages as $p) {
            if (! is_array($p)) {
                continue;
            }
            $content = $p['data']['content'] ?? [];
            if (is_array($content)) {
                $this->walkBlocks($content, $hist);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @param  array<string, int>  $hist
     */
    private function walkBlocks(array $blocks, array &$hist): void
    {
        foreach ($blocks as $b) {
            if (! is_array($b) || ! is_string($b['type'] ?? null)) {
                continue;
            }
            $hist[$b['type']] = ($hist[$b['type']] ?? 0) + 1;
            $props = is_array($b['props'] ?? null) ? $b['props'] : [];
            foreach ($props as $value) {
                if (is_array($value)) {
                    $this->walkBlocks($value, $hist);
                }
            }
        }
    }
}
