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
}
