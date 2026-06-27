<?php

declare(strict_types=1);

namespace App\Services\Plan;

// Deterministic detector for pages whose ENTIRE body is SportsEngine
// platform/tutorial content — e.g. a "SportsEngine Parents" how-to page
// templated by SE. Surfaced by the tbirdhoops IR-dump report: those pages
// get faithfully rebuilt by the IR pass today, but the rebuilt TeamLinkt
// site should carry zero SE onboarding content. PLAN's existing rules
// catch SE-platform LINKS in nav (Dibs toolsLink, /sportsengine URL); this
// detector catches SE-platform CONTENT.
//
// THE THREE-SIGNAL RULE (all required to park):
//
//   1. se_platform_links >= LINK_FLOOR (3)
//      Floor against thin pages with a single mention.
//
//   2. se_platform_links / total_outbound_links >= LINK_RATIO_MIN (0.70)
//      Density signal — the page must be OVERWHELMINGLY SE-tutorial-linked.
//
//   3. vocab_phrases_matched >= VOCAB_FLOOR (2)
//      VOCABULARY IS LOAD-BEARING — NOT a co-equal third check.
//
// Why three, not just two, and why vocab is critical (do not remove it):
//
//   The link signals catch "link-dense to SE-tutorial hosts". By itself
//   that over-parks legitimate org-authored pages — a curated "useful SE
//   guides for parents" page, or a registration walkthrough that deep-
//   links to a few SE help articles for specific steps, can hit ratio + floor
//   while being genuinely the org's own content. False-park is destructive:
//   the rebuilt site silently loses a real page.
//
//   The vocab signal is what distinguishes "org page that points AT SE"
//   from "page that IS SE-templated content". An org writing its own page
//   says "click here" / "the app" / "your account". SE-templated content
//   says "MySE" / "the SE Bar" / "Team Management Guide" — first-person
//   SE product vocabulary. Requiring 2+ DISTINCT phrases from that
//   vocabulary is the defensive belt against the curated-links false-park.
//
//   Without the vocab guard, a future maintainer reading "we already
//   require 70% ratio AND 3 links" might tighten link thresholds and
//   declare the detector "stricter" — and silently introduce false-parks.
//   Keep all three.
//
// PARK direction reverses PLAN's usual recall bias. For platform_dynamic
// or LLM park, false-keep is safe and false-park is destructive — so the
// thresholds bias toward keep. This detector follows the same principle:
// borderline pages STAY KEPT. Conservative thresholds, three reinforcing
// signals, all-or-nothing — the bar is "overwhelmingly SE templated", not
// "smells SE-ish".
final class SePlatformContentDetector
{
    public const LINK_FLOOR = 3;

    public const LINK_RATIO_MIN = 0.70;

    public const VOCAB_FLOOR = 2;

    /**
     * SE-platform-tutorial hosts/path patterns. A link is "se_platform"
     * iff it matches one of these. The list is intentionally narrow:
     * help / mobile-help / intercom / user dashboard / SE solutions
     * marketing / SE app store entries. Each entry is a regex anchored at
     * scheme so a path-anywhere-in-URL accidental match can't fire.
     *
     * @var array<int, string>
     */
    public const SE_PLATFORM_PATTERNS = [
        '#^https?://help\.sportsengine\.com/#i',
        '#^https?://mobile-help\.sportsengine\.com/#i',
        '#^https?://intercom\.help/SportsEngine/#i',
        '#^https?://my\.sportngin\.com/user/#i',
        '#^https?://(www\.)?sportsengine\.com/solutions/#i',
        '#^https?://itunes\.apple\.com/[^/]+/app/sport-ngin/#i',
        '#^https?://play\.google\.com/store/apps/details\?id=com\.sportngin\.#i',
    ];

    /**
     * Hosts/paths that are SHAPED LIKE sportsengine but are actually the
     * org's own content on SE infrastructure — these are EXCLUDED from the
     * SE-platform link count so a page that mentions them isn't penalised:
     *
     *   - <tenant>.sportngin.com/register/form/*  → tenant registration URL
     *     (the org's own registration form, hosted on SE)
     *   - cdn[N].sportngin.com/attachments/*      → inline media (photos,
     *     banner graphics, document attachments uploaded by the org)
     *
     * Login/signin paths (/sn_signin, /sn_login, /se_login, /se_signin) are
     * out of scope for this detector — they're handled by PLAN's existing
     * nav-link rules (isSePlatformLink), which would have parked them
     * before they reached this detector.
     *
     * @var array<int, string>
     */
    public const EXCLUDED_FROM_SE_PLATFORM_PATTERNS = [
        '#^https?://[a-z0-9-]+\.sportngin\.com/register/form/#i',
        '#^https?://cdn[0-9]*\.sportngin\.com/attachments/#i',
    ];

    /**
     * Distinct SE-platform vocabulary phrases. Substring match,
     * case-insensitive. A page mentioning the same phrase five times
     * scores 1, not 5 — the signal is DISTINCT phrase count.
     *
     * @var array<int, string>
     */
    public const VOCAB_PHRASES = [
        'myse',
        'the se bar',
        'your sportsengine account',
        'sportsengine accounts',
        'team management guide',
        'sportsengine platform',
        'sportsengine mobile app',
        'my.sportngin',
    ];

    public function detect(string $markdown): SePlatformContentVerdict
    {
        $links = $this->extractOutboundLinks($markdown);
        $sePlatform = [];
        foreach ($links as $url) {
            if ($this->isSePlatformLink($url)) {
                $sePlatform[] = $url;
            }
        }

        $total = count($links);
        $seCount = count($sePlatform);
        $ratio = $total > 0 ? $seCount / $total : 0.0;

        $vocabMatched = $this->matchedVocab($markdown);
        $vocabCount = count($vocabMatched);

        $is = $seCount >= self::LINK_FLOOR
            && $ratio >= self::LINK_RATIO_MIN
            && $vocabCount >= self::VOCAB_FLOOR;

        return new SePlatformContentVerdict(
            is_se_platform: $is,
            total_outbound_links: $total,
            se_platform_links: $seCount,
            ratio: $ratio,
            vocab_phrases_matched: $vocabMatched,
        );
    }

    /**
     * Public for tests — proves a given URL classifies as SE-platform
     * (or not). Real callers go through detect().
     */
    public function isSePlatformLink(string $url): bool
    {
        foreach (self::EXCLUDED_FROM_SE_PLATFORM_PATTERNS as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return false;
            }
        }
        foreach (self::SE_PLATFORM_PATTERNS as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Outbound links from the markdown body. Strips:
     *   - inline CDN media (already filtered by isSePlatformLink, but
     *     excluded from the total link count too so a photo-heavy page
     *     doesn't inflate the denominator)
     *   - same-page anchors (#yieldContent and friends)
     *
     * @return array<int, string>
     */
    private function extractOutboundLinks(string $markdown): array
    {
        if (preg_match_all('/\]\((https?:\/\/[^)\s]+)\)/i', $markdown, $m) === false) {
            return [];
        }

        /** @var array<int, string> $out */
        $out = [];
        foreach ($m[1] as $url) {
            if (str_contains($url, '#yieldContent')) {
                continue;
            }
            if (preg_match('#^https?://cdn[0-9]*\.sportngin\.com/attachments/#i', $url) === 1) {
                // Inline media — not a navigation link, never count.
                continue;
            }
            $out[] = $url;
        }

        return $out;
    }

    /**
     * @return array<int, string> distinct lowercase vocab phrases matched
     */
    private function matchedVocab(string $markdown): array
    {
        $haystack = mb_strtolower($markdown);
        /** @var array<int, string> $matched */
        $matched = [];
        foreach (self::VOCAB_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                $matched[] = $phrase;
            }
        }

        return $matched;
    }
}
