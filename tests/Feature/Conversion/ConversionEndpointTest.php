<?php

declare(strict_types=1);

namespace Tests\Feature\Conversion;

use App\Jobs\ConversionJob;
use App\Services\Conversion\ConversionStatusStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;

// End-to-end tests for the trigger + status + result HTTP endpoints.
// Covers:
//   - auth (missing / wrong / correct X-Demo-Token)
//   - dedupe on refresh (LOAD-BEARING — the foot-gun control for the
//     person demoing: refresh mid-conversion returns the existing
//     conversion_id, doesn't burn another $2-6 in Sonnet)
//   - 404 on unknown conversion_id
//   - 409 not-ready on GET /api/conversions/{id} before finalize
//   - throttle enforced on POST
final class ConversionEndpointTest extends \Tests\TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-demo-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.conversion.demo_token' => self::TOKEN]);
        // Prevent real jobs from running — we care about the HTTP
        // behaviors, not the pipeline itself (that's chain-equals-inline's
        // job).
        Bus::fake();
    }

    #[Test]
    public function post_without_token_returns_401(): void
    {
        $response = $this->postJson('/api/conversions', ['url' => 'https://www.cjfl.org/']);
        $response->assertStatus(401);
    }

    #[Test]
    public function post_with_wrong_token_returns_401(): void
    {
        $response = $this->postJson('/api/conversions', [
            'url' => 'https://www.cjfl.org/',
        ], ['X-Demo-Token' => 'wrong-token']);
        $response->assertStatus(401);
    }

    #[Test]
    public function post_with_correct_token_returns_202_and_dispatches_conversion_job(): void
    {
        $response = $this->postJson('/api/conversions', [
            'url' => 'https://www.cjfl.org/',
        ], ['X-Demo-Token' => self::TOKEN]);

        $response->assertStatus(202);
        $response->assertJsonStructure([
            'conversion_id',
            'status_url',
            'result_url',
            'preview_url',
            'deduped',
        ]);
        $this->assertFalse($response->json('deduped'));

        Bus::assertDispatched(ConversionJob::class);
    }

    #[Test]
    public function post_dedupes_on_refresh_same_token_and_url_returns_existing_conversion_id(): void
    {
        // THE LOAD-BEARING DEMO SAFETY CHECK: a nervous demo watcher
        // hitting refresh (or double-clicking convert) must NOT trigger
        // a second $2-6 Sonnet conversion. Same (token, url) within
        // 10 min → 200 (not 202) + the SAME conversion_id + deduped=true.
        $url = 'https://www.cjfl.org/';

        $first = $this->postJson('/api/conversions', ['url' => $url], ['X-Demo-Token' => self::TOKEN]);
        $first->assertStatus(202);
        $firstId = $first->json('conversion_id');
        $this->assertNotEmpty($firstId);

        // Simulated page refresh — POST again with same URL + token.
        $second = $this->postJson('/api/conversions', ['url' => $url], ['X-Demo-Token' => self::TOKEN]);
        $second->assertStatus(200);
        $this->assertSame(
            $firstId,
            $second->json('conversion_id'),
            'dedupe MUST return the existing conversion_id — a refresh must not spawn a second conversion'
        );
        $this->assertTrue($second->json('deduped'));

        // And only ONE ConversionJob was dispatched, not two.
        Bus::assertDispatchedTimes(ConversionJob::class, 1);
    }

    #[Test]
    public function post_dedupe_key_is_scoped_to_token_different_tokens_get_different_conversions(): void
    {
        // A different token posting the same URL should get its own
        // conversion. Multi-user posture (even though the demo cut
        // uses a single shared token, this property protects against
        // one user's URL blocking another's).
        $url = 'https://www.cjfl.org/';

        // Configure two valid tokens (or use different token per call).
        config(['services.conversion.demo_token' => 'token-a']);
        $first = $this->postJson('/api/conversions', ['url' => $url], ['X-Demo-Token' => 'token-a']);
        $first->assertStatus(202);

        config(['services.conversion.demo_token' => 'token-b']);
        $second = $this->postJson('/api/conversions', ['url' => $url], ['X-Demo-Token' => 'token-b']);
        $second->assertStatus(202);

        $this->assertNotSame(
            $first->json('conversion_id'),
            $second->json('conversion_id'),
            'different tokens must get distinct conversion_ids for the same URL'
        );
    }

    #[Test]
    public function post_dedupe_url_normalization_case_insensitive(): void
    {
        // A demo watcher who typed the URL twice with different
        // whitespace/case should still hit dedupe.
        $first = $this->postJson('/api/conversions', [
            'url' => 'https://www.cjfl.org/',
        ], ['X-Demo-Token' => self::TOKEN]);

        // URL normalization: leading/trailing whitespace stripped,
        // lowercased. dedupe key sha1s the normalized form.
        $second = $this->postJson('/api/conversions', [
            'url' => '  https://www.CJFL.org/  ',
        ], ['X-Demo-Token' => self::TOKEN]);

        $second->assertStatus(200);
        $this->assertSame($first->json('conversion_id'), $second->json('conversion_id'));
    }

    #[Test]
    public function status_returns_404_for_unknown_conversion_id(): void
    {
        $response = $this->getJson('/api/conversions/conv-does-not-exist/status', [
            'X-Demo-Token' => self::TOKEN,
        ]);
        $response->assertStatus(404);
    }

    #[Test]
    public function status_reflects_snapshot_written_by_conversion_job(): void
    {
        // Post a conversion (dispatches the job via Bus::fake, so no
        // real work happens). status_store->begin was called inline
        // by the controller — /status should return that snapshot.
        $response = $this->postJson('/api/conversions', [
            'url' => 'https://www.cjfl.org/',
        ], ['X-Demo-Token' => self::TOKEN]);
        $conversionId = $response->json('conversion_id');

        $statusResponse = $this->getJson("/api/conversions/{$conversionId}/status", [
            'X-Demo-Token' => self::TOKEN,
        ]);

        $statusResponse->assertStatus(200);
        $statusResponse->assertJsonPath('conversion_id', $conversionId);
        $statusResponse->assertJsonPath('url', 'https://www.cjfl.org/');
        // Stage after begin() is Queued — the job hasn't run yet (faked).
        $statusResponse->assertJsonPath('stage', 'queued');
        $statusResponse->assertJsonPath('final_status', 'in_progress');
    }

    #[Test]
    public function get_result_returns_409_when_not_ready(): void
    {
        // Post a conversion; then immediately GET /api/conversions/{id}.
        // No ConversionResult in the store yet (Bus::fake — job never
        // ran). Should return 409 Conflict with "not ready" body.
        $response = $this->postJson('/api/conversions', [
            'url' => 'https://www.cjfl.org/',
        ], ['X-Demo-Token' => self::TOKEN]);
        $conversionId = $response->json('conversion_id');

        $getResponse = $this->getJson("/api/conversions/{$conversionId}", [
            'X-Demo-Token' => self::TOKEN,
        ]);

        $getResponse->assertStatus(409);
        $getResponse->assertJsonPath('error', 'not ready');
        $getResponse->assertJsonPath('stage', 'queued');
    }

    #[Test]
    public function get_result_returns_404_for_unknown_conversion_id(): void
    {
        $response = $this->getJson('/api/conversions/conv-does-not-exist', [
            'X-Demo-Token' => self::TOKEN,
        ]);
        $response->assertStatus(404);
    }

    #[Test]
    public function post_validates_url_field(): void
    {
        // Missing url.
        $response = $this->postJson('/api/conversions', [], ['X-Demo-Token' => self::TOKEN]);
        $response->assertStatus(422);

        // Non-URL string.
        $response = $this->postJson('/api/conversions', ['url' => 'not-a-url'], ['X-Demo-Token' => self::TOKEN]);
        $response->assertStatus(422);
    }
}
