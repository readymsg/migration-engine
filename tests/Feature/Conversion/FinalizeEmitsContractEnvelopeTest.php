<?php

declare(strict_types=1);

namespace Tests\Feature\Conversion;

use App\Data\ConversionStage;
use App\Data\Manifest;
use App\Data\OrgType;
use App\Data\SiteImport\Asset;
use App\Data\SiteImport\Diagnostic;
use App\Data\SiteImport\Envelope;
use App\Data\SiteImport\Page;
use App\Data\SiteImport\SiteSettings;
use App\Data\SiteImport\Source;
use App\Jobs\ConversionJob;
use App\Services\ContractEmitter\ContractEnvelopeStore;
use App\Services\Conversion\ConversionResultStore;
use App\Services\Conversion\ConversionStatusStore;
use App\Services\Extract\Extractor;
use App\Services\Generate\BlockFillAgent;
use App\Services\Plan\ClassifierAgent;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\Support\Generate\FakeBlockFillAgent;
use Tests\Support\Plan\FakeClassifierAgent;
use Tests\Support\Plan\RealManifests;
use Tests\TestCase;

// Slice-post-17 wiring test. Proves the live pipeline emits a
// contract envelope alongside the ConversionResult, and that the
// envelope is retrievable by conversion id via the store.
//
// Uses the same offline stack as ChainEqualsInlineTest (fake agents
// + real fixture manifest) — no LLM cost, deterministic. The
// envelope produced on this offline path will be sparse (empty
// page_map because FakeFirecrawlClient returns no scraped bodies)
// but the STORE + WIRING contract is exercised end-to-end.
final class FinalizeEmitsContractEnvelopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Fakes for classifier + block-fill so no LLM calls.
        $this->app->instance(BlockFillAgent::class, new FakeBlockFillAgent);
        $this->app->instance(ClassifierAgent::class, new FakeClassifierAgent);
    }

    #[Test]
    public function finalize_writes_envelope_to_store_retrievable_by_conversion_id(): void
    {
        // Wire the extractor to return the offline fixture manifest
        // so the pipeline runs end-to-end without hitting the
        // network.
        $manifest = RealManifests::tbirdhoops();
        $this->app->instance(Extractor::class, new class($manifest) implements Extractor
        {
            public function __construct(private readonly Manifest $manifest) {}

            public function extract(string $url): Manifest
            {
                return $this->manifest;
            }
        });

        $conversionId = 'conv-envelope-test';
        app(ConversionStatusStore::class)->begin($conversionId, 'https://www.tbirdhoops.org/');
        ConversionJob::dispatch($conversionId, 'https://www.tbirdhoops.org/', OrgType::Club);

        // Envelope MUST be present in the store after finalize.
        $envelope = app(ContractEnvelopeStore::class)->get($conversionId);
        $this->assertNotNull(
            $envelope,
            'contract envelope must be persisted to the store after Finalize runs',
        );
        $this->assertSame(1, $envelope->schemaVersion);
    }

    #[Test]
    public function envelope_validation_errors_appear_in_conversion_result_failures(): void
    {
        // The offline pipeline produces an empty page_map (fake
        // firecrawl → no scraped bodies → nothing block-filled),
        // which fires the envelope validator's `homepage_count_wrong`
        // error (no page has slug=""). That error MUST surface as a
        // `contract-emit` ConversionFailure in the persisted
        // ConversionResult — same visibility as every other pipeline
        // failure.
        $manifest = RealManifests::tbirdhoops();
        $this->app->instance(Extractor::class, new class($manifest) implements Extractor
        {
            public function __construct(private readonly Manifest $manifest) {}

            public function extract(string $url): Manifest
            {
                return $this->manifest;
            }
        });

        $conversionId = 'conv-fail-test';
        app(ConversionStatusStore::class)->begin($conversionId, 'https://www.tbirdhoops.org/');
        ConversionJob::dispatch($conversionId, 'https://www.tbirdhoops.org/', OrgType::Club);

        $conversion = app(ConversionResultStore::class)->get($conversionId);
        $this->assertNotNull($conversion);
        $contractEmitFailures = array_filter(
            $conversion->failures->items(),
            fn ($f) => $f->stage === ConversionStage::ContractEmit,
        );
        $this->assertNotEmpty(
            $contractEmitFailures,
            'empty page_map → homepage_count_wrong validation must surface as a contract-emit failure',
        );
    }

    #[Test]
    public function envelope_route_serves_live_conversion(): void
    {
        // Directly plant an envelope + assert the /api/preview-contract
        // route serves it. Bypasses the full pipeline for surface
        // coverage of the ContractPreviewController path.
        $envelope = new Envelope(
            schemaVersion: 1,
            source: new Source(
                url: 'https://x.com',
                scrapedAt: '2026-08-21T00:00:00Z',
                pagesDiscovered: 1,
                pagesMapped: 1,
            ),
            site: new SiteSettings(primaryColor: '#AE292E'),
            pages: new DataCollection(Page::class, []),
            assets: new DataCollection(Asset::class, []),
            diagnostics: new DataCollection(Diagnostic::class, []),
        );
        app(ContractEnvelopeStore::class)->put('conv-route-test', $envelope);

        $response = $this->get('/api/preview-contract/conv-route-test/envelope');
        $response->assertStatus(200);
        $response->assertJsonPath('schemaVersion', 1);
        $response->assertJsonPath('site.primaryColor', '#AE292E');

        // 404 when no envelope for that id.
        $this->get('/api/preview-contract/conv-nonexistent/envelope')->assertStatus(404);
    }
}
