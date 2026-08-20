<?php

declare(strict_types=1);

namespace App\Services\Conversion;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

// Cost-safety layer for the public-facing trigger endpoint. Level 2
// (URL allowlist) stacked with Level 1 (daily spend cap + concurrency
// lock) — see CLAUDE.md's "Hosted demo" section for the full model.
//
// LOAD-BEARING: without these guards, a public $DEMO_TOKEN embedded in
// client-side JS is trivially extractable + the /api/conversions POST
// route bills real $3-6 per call in Sonnet/Firecrawl. A single Discord
// post can run up hundreds of dollars in an afternoon. These guards
// bound cost to a predictable daily ceiling.
//
// Guards fire in this order in the controller:
//
//   1. token gate           (EnsureDemoToken middleware, already exists)
//   2. URL validation       (Laravel validator, already exists)
//   3. allowlist            ← check() → 400 if URL not in DEMO_URL_ALLOWLIST
//   4. daily budget         ← check() → 429 if daily $ spend would exceed cap
//   5. dedupe               (ConversionDedupeStore, already exists — 24h TTL for allowlisted URLs)
//   6. concurrency          ← check() → 409 if another conversion is in-flight (fresh dispatch only, not on dedupe hits)
//   7. commit + dispatch
//
// Dedupe SITS BETWEEN the pre-dispatch guards (3-4) and the in-flight
// guards (6) so dedupe hits SKIP the concurrency check (they're not
// starting a new conversion — they're serving the existing one).
final class ConversionCostGuard
{
    private const DAILY_BUDGET_KEY_PREFIX = 'conversion:daily-spend-cents:';

    private const CONCURRENCY_KEY = 'conversion:in-flight-count';

    private const CONCURRENCY_TTL_SECONDS = 3600; // 60-min cap so a leaked counter clears itself

    private const DAILY_KEY_TTL_SECONDS = 172_800; // 48h — yesterday's counter expires by tomorrow's tomorrow

    // Conservative per-conversion cost estimate. Real cjfl (34 pages)
    // ran ~$3 in Sonnet + IR-pass Opus. 400 cents = $4 leaves headroom
    // for larger sites without underestimating the budget.
    private const ESTIMATED_CONVERSION_CENTS = 400;

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Pre-dispatch check. Runs allowlist + daily budget. Concurrency
     * is checked separately AFTER dedupe (dedupe hits shouldn't be
     * blocked by concurrency — they're not new work).
     *
     * @return array{allowed: true}|array{allowed: false, error: string, status: int}
     */
    public function checkPreDedupe(string $url): array
    {
        // 1. Allowlist. When DEMO_URL_ALLOWLIST is empty (dev/local),
        //    no allowlist enforced — arbitrary URLs allowed. When set
        //    (prod / hosted demo), URL must match the normalized form
        //    of a listed entry.
        $allowlist = $this->allowlist();
        if ($allowlist !== null && ! in_array($this->normalizeUrl($url), $allowlist, true)) {
            return [
                'allowed' => false,
                'error' => 'This demo only converts a curated set of SportsEngine sites. Contact us to add yours.',
                'status' => 400,
            ];
        }

        // 2. Daily budget. Cache-backed counter, keyed by UTC day.
        //    Each fresh dispatch adds ESTIMATED_CONVERSION_CENTS.
        //    Dedupe hits do NOT touch this counter (no fresh cost).
        $todayKey = $this->dailyKey();
        $spentCents = (int) ($this->cache->get($todayKey) ?? 0);
        $budgetCents = $this->dailyBudgetUsd() * 100;
        if ($spentCents + self::ESTIMATED_CONVERSION_CENTS > $budgetCents) {
            return [
                'allowed' => false,
                'error' => 'Daily demo budget reached. The demo resumes tomorrow.',
                'status' => 429,
            ];
        }

        return ['allowed' => true];
    }

    /**
     * In-flight concurrency check. Runs AFTER dedupe (dedupe hits are
     * legitimate; they don't consume concurrency budget). Returns
     * blocking status if another conversion is currently running.
     *
     * @return array{allowed: true}|array{allowed: false, error: string, status: int}
     */
    public function checkConcurrency(): array
    {
        $inFlight = (int) ($this->cache->get(self::CONCURRENCY_KEY) ?? 0);
        $cap = $this->concurrentLimit();
        if ($inFlight >= $cap) {
            return [
                'allowed' => false,
                'error' => 'Another conversion is running right now. Please try again in a moment.',
                'status' => 409,
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Called AFTER a fresh conversion is dispatched (NOT on dedupe
     * hits). Increments both the daily spend counter and the
     * concurrency counter. Idempotent-safe under mild races (we
     * accept small counter drift for a demo cut; a tight lock would
     * be over-engineering).
     */
    public function commitDispatch(): void
    {
        $todayKey = $this->dailyKey();
        $this->cache->increment($todayKey, self::ESTIMATED_CONVERSION_CENTS);
        // Ensure TTL is set (increment() on a missing key initializes
        // to the increment amount but doesn't set TTL — belt-and-braces).
        $current = (int) ($this->cache->get($todayKey) ?? 0);
        $this->cache->put($todayKey, $current, self::DAILY_KEY_TTL_SECONDS);

        $this->cache->increment(self::CONCURRENCY_KEY);
        $inFlight = (int) ($this->cache->get(self::CONCURRENCY_KEY) ?? 0);
        // Same TTL guard on concurrency — if a job crashes past
        // release(), the counter still expires within 60 min so the
        // demo self-heals.
        $this->cache->put(self::CONCURRENCY_KEY, $inFlight, self::CONCURRENCY_TTL_SECONDS);
    }

    /**
     * Called when a conversion terminates (Complete/Partial/Failed).
     * Decrements the concurrency counter so the next conversion can
     * start. Daily counter is NOT decremented — spent is spent.
     *
     * Best-effort: if the counter was already 0 (e.g., release()
     * called twice, or the counter cleared via TTL), no-op. Prevents
     * negative counter drift.
     */
    public function releaseConcurrency(): void
    {
        $current = (int) ($this->cache->get(self::CONCURRENCY_KEY) ?? 0);
        if ($current <= 0) {
            return;
        }
        $this->cache->decrement(self::CONCURRENCY_KEY);
    }

    /**
     * True when the URL is on the allowlist (used by the controller
     * to pick a longer dedupe TTL for known-safe URLs).
     */
    public function isAllowlisted(string $url): bool
    {
        $allowlist = $this->allowlist();
        if ($allowlist === null) {
            // No allowlist configured — treat everything as
            // "not-known-safe" so dedupe uses the default 10-min TTL.
            // This preserves the demo-cut's tighter timing on unknown
            // URLs; hosted-with-allowlist sites get 24h.
            return false;
        }

        return in_array($this->normalizeUrl($url), $allowlist, true);
    }

    /**
     * The normalized allowlist for rendering in the landing-page chips.
     * Returns raw (pre-normalization) URLs so the frontend can display
     * user-friendly labels like "https://www.cjfl.org/".
     *
     * @return array<int, string>
     */
    public function rawAllowlistForFrontend(): array
    {
        $raw = (string) config('services.conversion.url_allowlist', '');
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * @return ?array<int, string> null = no allowlist configured, all URLs allowed. Otherwise the normalized allowlisted URLs.
     */
    private function allowlist(): ?array
    {
        $raw = $this->rawAllowlistForFrontend();
        if ($raw === []) {
            return null;
        }

        return array_map(fn (string $u): string => $this->normalizeUrl($u), $raw);
    }

    /**
     * URL normalization — lowercase host + path, trim whitespace, drop
     * trailing slash. Preserves query and fragment so `?utm=…` still
     * matches an allowlisted `?utm=…` (deliberate — the allowlist
     * matches the FULL URL, not just the origin). If we later want
     * origin-only matching, revisit.
     */
    private function normalizeUrl(string $url): string
    {
        return rtrim(strtolower(trim($url)), '/');
    }

    private function dailyKey(): string
    {
        return self::DAILY_BUDGET_KEY_PREFIX.gmdate('Y-m-d');
    }

    private function dailyBudgetUsd(): int
    {
        $configured = config('services.conversion.daily_budget_usd', 30);
        if (! is_numeric($configured)) {
            return 30;
        }

        return max(0, (int) $configured);
    }

    private function concurrentLimit(): int
    {
        $configured = config('services.conversion.concurrent_limit', 1);
        if (! is_numeric($configured)) {
            return 1;
        }

        return max(1, (int) $configured);
    }
}
