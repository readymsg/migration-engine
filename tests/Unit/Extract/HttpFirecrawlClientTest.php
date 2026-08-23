<?php

declare(strict_types=1);

namespace Tests\Unit\Extract;

use App\Services\Extract\HttpFirecrawlClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Pins the on-the-wire shape of the v2 /scrape POST so a future refactor
// can't silently drop the params we depend on. Specifically:
//   - onlyMainContent: true — strips SE nav/menu/sign-in chrome. Without it
//     every page body had ~1400 chars of repeating chrome prepended (real,
//     observed during the live tbirdhoops probe).
//   - excludeTags: CSS #id selectors for SE itasca chrome that onlyMainContent
//     misses (Firecrawl's main-content detector doesn't classify plain divs
//     without semantic nav/header tags as boilerplate). Order + membership
//     locked here — a regression in this list ships chrome back into the IR
//     input. v2 /scrape accepts CSS #id selectors (verified by live spike).
//   - maxAge: 0 — bypass Firecrawl's response cache so a re-extraction of
//     the same site after an upstream change returns fresh markdown. Also
//     made the cache-vs-not diagnosis possible.
// All live at the ROOT of the request body per the live v2 /scrape docs
// (verified 2026-06-24), NOT nested under any 'scrapeOptions' wrapper.
final class HttpFirecrawlClientTest extends TestCase
{
    /** The SE itasca chrome selectors. Mirrors HttpFirecrawlClient::EXCLUDE_TAGS — kept here as a
     *  literal so a silent change to the const trips this test, not a silent passthrough. */
    private const EXPECTED_EXCLUDE_TAGS = [
        '#ngin-bar',
        '#fb-root',
        '#topNav',
        '#topNavPlaceholder',
        '#PageSearch',
        '#overlay',
        '#lightbox',
    ];

    #[Test]
    public function scrape_request_body_pins_only_main_content_and_max_age_at_root(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data' => [
                    'markdown' => '# stub',
                    'html' => '',
                    'metadata' => [
                        'title' => 'stub',
                        'sourceURL' => 'https://example.com/p',
                    ],
                ],
            ], 200),
        ]);

        $client = new HttpFirecrawlClient(
            apiKey: 'test-key',
            baseUrl: 'https://api.firecrawl.dev/v2',
        );

        $client->scrape('https://example.com/p');

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            $this->assertSame('https://example.com/p', $body['url'] ?? null);
            $this->assertSame(['markdown', 'html', 'images'], $body['formats'] ?? null);

            // The two params this test exists to lock in.
            $this->assertArrayHasKey('onlyMainContent', $body, 'onlyMainContent must be sent explicitly — defaults drift');
            $this->assertTrue($body['onlyMainContent'], 'onlyMainContent must be true on the wire');

            $this->assertArrayHasKey('maxAge', $body, 'maxAge must be sent — cache bypass is intentional, not a default');
            $this->assertSame(0, $body['maxAge'], 'maxAge=0 means "never use cache" per v2 docs');

            // excludeTags — the SE itasca chrome selectors. Order + exact membership
            // matters: each id was vetted against the captured DOM as chrome-only, and
            // a silent reordering or addition risks over-stripping real content.
            $this->assertArrayHasKey('excludeTags', $body, 'excludeTags must be sent — defaults will not strip SE itasca chrome');
            $this->assertSame(
                self::EXPECTED_EXCLUDE_TAGS,
                $body['excludeTags'],
                'excludeTags list drift — verify the new selectors are still chrome-only against the DOM before changing this assertion',
            );

            // None of the locked params may be nested under a wrapper.
            $this->assertArrayNotHasKey('scrapeOptions', $body, 'v2 puts these at the request root, not under scrapeOptions');

            // Hits the v2 endpoint, not v1.
            $this->assertSame('https://api.firecrawl.dev/v2/scrape', $request->url());

            // Bearer token went out.
            $this->assertSame('Bearer test-key', $request->header('Authorization')[0] ?? null);

            return true;
        });
    }

    #[Test]
    public function returns_null_when_firecrawl_reports_success_false(): void
    {
        Http::fake([
            '*' => Http::response(['success' => false, 'error' => 'page blocked'], 200),
        ]);

        $client = new HttpFirecrawlClient(apiKey: 'test-key');
        $result = $client->scrape('https://example.com/blocked');

        $this->assertNull($result, 'success=false (with a 2xx) must surface as null, not throw — the extractor wraps null into a content_failure');
    }

    #[Test]
    public function throws_when_api_key_is_empty(): void
    {
        $client = new HttpFirecrawlClient(apiKey: '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Firecrawl API key not configured/');
        $client->scrape('https://example.com/anything');
    }

    // ─── retry + throttle (Finding 1 fix) ───────────────────────────────

    #[Test]
    public function retries_on_429_then_succeeds_on_second_attempt(): void
    {
        // Two responses: first 429, second success. Retry loop takes
        // the second. Assert scrape returns a ScrapedPage AND that
        // the wire was hit exactly twice.
        Http::fakeSequence()
            ->push('rate limited', 429)
            ->push(['success' => true, 'data' => ['markdown' => '# ok', 'html' => '', 'images' => [], 'metadata' => ['sourceURL' => 'https://x/p']]], 200);

        $client = new HttpFirecrawlClient(
            apiKey: 'test-key',
            minIntervalMs: 0,   // no inter-request throttle in tests — usleep is real time
            maxAttempts: 3,
        );
        $result = $client->scrape('https://x/p');

        $this->assertNotNull($result);
        $this->assertSame('# ok', $result->markdown);
        Http::assertSentCount(2);
    }

    #[Test]
    public function retries_on_5xx_then_succeeds(): void
    {
        // 503 → 200. Same retry contract as 429 but with the base backoff.
        Http::fakeSequence()
            ->push('bad gateway', 503)
            ->push(['success' => true, 'data' => ['markdown' => '# ok', 'html' => '', 'images' => [], 'metadata' => []]], 200);

        $client = new HttpFirecrawlClient(apiKey: 'test-key', minIntervalMs: 0, maxAttempts: 3);
        $this->assertNotNull($client->scrape('https://x/p'));
        Http::assertSentCount(2);
    }

    #[Test]
    public function terminal_4xx_does_not_retry(): void
    {
        // 403 is not transient — should throw on the first attempt
        // with the attempt count in the message. Prevents burning
        // budget retrying auth failures.
        Http::fake([
            '*' => Http::response('forbidden', 403),
        ]);

        $client = new HttpFirecrawlClient(apiKey: 'test-key', minIntervalMs: 0, maxAttempts: 3);

        try {
            $client->scrape('https://x/p');
            $this->fail('expected RuntimeException on terminal 4xx');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 403', $e->getMessage());
            $this->assertStringContainsString('non-retryable', $e->getMessage());
            $this->assertStringContainsString('attempt 1', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    #[Test]
    public function throws_with_attempt_count_after_all_retries_exhausted(): void
    {
        // All 3 attempts return 429. Final throw names the attempt
        // count so the extractor's ContentExtractionFailure reason
        // makes it visible how many retries burned.
        Http::fake([
            '*' => Http::response('rate limited', 429),
        ]);

        $client = new HttpFirecrawlClient(apiKey: 'test-key', minIntervalMs: 0, maxAttempts: 3);
        try {
            $client->scrape('https://x/p');
            $this->fail('expected RuntimeException after retries exhausted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed after 3 attempts', $e->getMessage());
            $this->assertStringContainsString('HTTP 429', $e->getMessage());
        }
        Http::assertSentCount(3);
    }

    #[Test]
    public function respects_retry_after_header_on_429(): void
    {
        // A 429 with Retry-After: 1 (1 second) followed by success.
        // The client waits 1s before the retry, then succeeds.
        Http::fakeSequence()
            ->push('rate limited', 429, ['Retry-After' => '1'])
            ->push(['success' => true, 'data' => ['markdown' => '# ok', 'html' => '', 'images' => [], 'metadata' => []]], 200);

        $client = new HttpFirecrawlClient(apiKey: 'test-key', minIntervalMs: 0, maxAttempts: 3);
        $start = microtime(true);
        $result = $client->scrape('https://x/p');
        $elapsed = microtime(true) - $start;

        $this->assertNotNull($result);
        // Retry-After was 1s — must have slept at least ~0.95s.
        $this->assertGreaterThanOrEqual(0.95, $elapsed, 'must sleep >= Retry-After before retry');
        Http::assertSentCount(2);
    }

    #[Test]
    public function inter_request_throttle_sleeps_between_calls(): void
    {
        // Two successful scrapes with a 200ms throttle. Second call
        // must not fire until at least 200ms after the first ended.
        Http::fake([
            '*' => Http::response(['success' => true, 'data' => ['markdown' => '# ok', 'html' => '', 'images' => [], 'metadata' => []]], 200),
        ]);

        $client = new HttpFirecrawlClient(apiKey: 'test-key', minIntervalMs: 200, maxAttempts: 3);
        $client->scrape('https://x/p1');
        $start = microtime(true);
        $client->scrape('https://x/p2');
        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertGreaterThanOrEqual(180, $elapsed, 'second call must respect min-interval throttle (>= 180ms)');
    }
}
