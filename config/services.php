<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'firecrawl' => [
        'api_key' => env('FIRECRAWL_API_KEY'),
        'url' => env('FIRECRAWL_URL', 'https://api.firecrawl.dev/v2'),
        // Inter-request throttle. Firecrawl's rate limit is plan-scoped.
        // Documented limits (verify against your plan's dashboard):
        //   - Free:     3 rpm  → 20000ms
        //   - Hobby:   20 rpm  →  3000ms
        //   - Standard: 100 rpm →   600ms
        //   - Growth:   500 rpm →   120ms
        // Default 4000ms = 15 rpm, safe under Hobby. Set explicitly for
        // your plan to reduce wall-clock on large sites. Tuning target:
        // 34-page cjfl site at Hobby = 34 × 3s = ~100s scrape wall clock;
        // at Free = 34 × 20s = ~11min (probably where the 429 wall hit
        // during the first live cjfl run).
        'min_interval_ms' => (int) env('FIRECRAWL_MIN_INTERVAL_MS', 4000),
        // Retry budget for transient failures (429, 5xx, network). 4xx
        // other than 429 don't retry — those are terminal. On 429, we
        // respect Retry-After if present; otherwise use RATE_LIMIT_BACKOFF.
        'max_attempts' => (int) env('FIRECRAWL_MAX_ATTEMPTS', 3),
    ],

    // Filesystem disk (from config/filesystems.php) the S3AssetUploader
    // writes scrapes, brand assets, and re-hosted SE-CDN assets to. Defaults
    // to 's3' for prod; set SCRAPES_DISK=local in .env to route to local
    // storage during dev so captured content can be read back without a
    // bucket. The class name is historical — any configured disk works.
    'scrapes' => [
        'disk' => env('SCRAPES_DISK', 's3'),
    ],

    // Tier-4 async fixture replay: when true, bind BlockFillAgent to
    // FixtureReplayingBlockFillAgent instead of AnthropicBlockFillAgent.
    // Set BLOCKFILL_FIXTURE_REPLAY=1 in the environment BOTH for the
    // caller and for `php artisan horizon`. See
    // FixtureReplayingBlockFillAgent's docblock.
    'blockfill' => [
        'fixture_replay' => env('BLOCKFILL_FIXTURE_REPLAY') === '1',
    ],

    // Trigger-endpoint demo config. `demo_token` is the shared secret
    // callers must send as `X-Demo-Token`. Unset → trigger endpoint
    // returns 503 (prevents accidental prod exposure).
    //
    // The cost-guard fields are LOAD-BEARING for public exposure. See
    // ConversionCostGuard docblock + CLAUDE.md "Hosted demo" section.
    'conversion' => [
        'demo_token' => env('DEMO_TOKEN'),
        // Comma-separated URLs to allowlist (exact-match after
        // lowercase-trim-trailing-slash normalization). Empty →
        // no allowlist enforced (dev/local). Non-empty → hosted-demo
        // safe mode.
        'url_allowlist' => env('DEMO_URL_ALLOWLIST', ''),
        // Hard daily spend cap in USD. Cache-backed counter increments
        // ~$4 per fresh conversion dispatch (dedupe hits skip). When
        // exceeded, POST returns 429 until UTC midnight.
        'daily_budget_usd' => env('DEMO_DAILY_BUDGET_USD', 30),
        // In-flight concurrency ceiling. Second POST while another is
        // running returns 409 (dedupe hits still succeed — they
        // don't count as "another").
        'concurrent_limit' => env('DEMO_CONCURRENT_CONVERSIONS', 1),
    ],

];
