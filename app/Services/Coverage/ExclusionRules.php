<?php

declare(strict_types=1);

namespace App\Services\Coverage;

use App\Data\SourceElement;

// Deterministic classifier for source elements the rebuild INTENTIONALLY
// does not migrate. Everything that fires here becomes an EXCLUDED
// disposition on the coverage report — reported as a distinct line and
// excluded from the DROPPED count.
//
// DISCIPLINE: rules must be tight. Over-exclusion is silent loss
// disguised as "intentional" and is worse than a slightly-inflated
// DROPPED number. If a rule surface changes, the report's ratios
// change with it — visibly.
//
// Rules currently fire on:
//
//   RULE 1 — SE-platform chrome URLs
//     Links / images / documents whose host or path matches known
//     SportsEngine platform infrastructure that PLAN also parks:
//       - *.sportngin.com AND *.sportsengine.com host paths under
//         /solutions/, /user/, /user_sessions, /sn_signin, /sn_login,
//         /se_login, /se_signin, /dib_sessions, /dibs
//       - sportsengine-prelive.com (SE preview environment)
//       - itunes.apple.com/*/app/sport-ngin/
//       - play.google.com/store/apps/details?id=com.sportngin.
//     Rebuilt site must have zero live SE dependency — these are
//     platform chrome, not org content.
//
//   RULE 2 — Skip-nav / anchor-only URLs
//     Fragment-only hrefs and skip-navigation anchors
//     (#yieldContent / #skipContent / #skip). Rendering chrome, not
//     content. SourceElementCounter already filters most; this rule
//     is the belt-and-suspenders check.
//
//   RULE 3 — Same-site chrome duplicates
//     Links pointing at the SAME site's rootNav-managed pages
//     (`https://<origin>/home`, `/aboutus`, `/parents`, etc.). These
//     appear in every scrape because SE renders a shared nav /
//     footer. The rebuild carries its own nav; per-page duplicates
//     of that nav are chrome. Only fires when the href host is the
//     same as the source_url in the manifest.
//
//   RULE 4 — Unsubscribe / legal boilerplate prose
//     Prose lines that are pure legal / opt-out boilerplate
//     ("Unsubscribe", "You are receiving this because", "Privacy
//     Policy", "Terms of Service"). Whole-line match against a small
//     closed phrase list — no fuzzy detection.
//
//   RULE 5 — Stale live-widget artefact prose
//     Whole-line prose that is source-site widget chrome:
//       - countdown unit labels ("Days", "Hours", "Minutes",
//         "Seconds") and their decorated numeric forms ("Days**0**",
//         "**0** Days"). Scraped as static text after SE's live JS
//         widget didn't run.
//       - bare timestamp fragments (00:00, 1:23) with no surrounding
//         prose.
//       - video-player chrome ("StopPlay", "About JW Player…").
//     Tight by design — a false EXCLUDED here would hide real
//     content loss. A sentence merely CONTAINING a time or the word
//     "hours" is org content and MUST NOT be excluded.
final class ExclusionRules
{
    /** @var array<int, string> normalized needles that mark an SE-platform href */
    private const SE_PLATFORM_HREF_NEEDLES = [
        'sportsengine-prelive.com',
        'itunes.apple.com/us/app/sport-ngin/',
        'play.google.com/store/apps/details?id=com.sportngin.',
        'sportsengine.com/solutions/',
        '/user_sessions',
        '/sn_signin',
        '/sn_login',
        '/se_signin',
        '/se_login',
        '/dib_sessions',
        '/dibs',
        'my.sportngin.com/user/',
        'my.sportsengine.com/user/',
    ];

    // Stale live-widget artefact patterns (RULE 5). Whole-line / whole-
    // fragment match ONLY, after stripping markdown emphasis and
    // collapsing whitespace. A sentence that MERELY CONTAINS a time or
    // the word "hours" is org content and must not be excluded. The
    // rule is tight by design — a false EXCLUDED here would hide real
    // content loss.
    //
    // Categories:
    //   - countdown unit labels ("Days", "Hours", "Minutes", "Seconds")
    //     alone or paired with a single decorated number ("Days 0",
    //     "0 Days", "**0** Days" → normalises to "0 days" or "days 0")
    //   - bare timestamp fragments ("00:00", "1:23") — nothing else on
    //     the line
    //   - JW Player and video-player chrome ("StopPlay", "About JW
    //     Player 6.12.4956")
    /** @var array<int, string> whole-line regex patterns tested after normalisation */
    private const WIDGET_ARTEFACT_PATTERNS = [
        '/^(days?|hours?|minutes?|seconds?)$/',
        '/^\d+\s*(days?|hours?|minutes?|seconds?)$/',
        '/^(days?|hours?|minutes?|seconds?)\s*\d+$/',
        '/^\d{1,2}:\d{2}$/',
        '/^(stop\s?play|play\s?pause|play|pause|mute|unmute)$/',
        '/^about jw player\s*[\d.]*\s*$/',
    ];

    /** @var array<int, string> unsubscribe / legal boilerplate needles (case-insensitive, exact-line match) */
    private const LEGAL_BOILERPLATE_NEEDLES = [
        'unsubscribe',
        'you are receiving this email because',
        'privacy policy',
        'terms of service',
        'terms of use',
        'unsubscribe from this list',
        'manage your email preferences',
        'unsubscribe here',
    ];

    /**
     * @return array{excluded: bool, rule: string, reason: string}
     */
    public function classify(SourceElement $element, ?string $sourceOrigin = null): array
    {
        $notExcluded = ['excluded' => false, 'rule' => '', 'reason' => ''];

        if (in_array($element->kind, ['link', 'image', 'document'], true)) {
            $url = trim($element->content);
            if ($url === '') {
                return $notExcluded;
            }
            $urlLower = mb_strtolower($url);

            // RULE 1
            foreach (self::SE_PLATFORM_HREF_NEEDLES as $needle) {
                if (str_contains($urlLower, mb_strtolower($needle))) {
                    return [
                        'excluded' => true,
                        'rule' => 'SE-platform chrome URL',
                        'reason' => "url matches SE-platform infra pattern '{$needle}'",
                    ];
                }
            }

            // RULE 2
            if (str_starts_with($url, '#') || str_contains($urlLower, '#yieldcontent') || str_contains($urlLower, '#skipcontent')) {
                return [
                    'excluded' => true,
                    'rule' => 'skip-nav / anchor-only URL',
                    'reason' => 'href is a page-internal anchor / skip-nav target, not content',
                ];
            }

            // RULE 3 — same-site chrome duplicate
            if ($sourceOrigin !== null && $sourceOrigin !== '') {
                $originHost = $this->hostOf($sourceOrigin);
                $urlHost = $this->hostOf($url);
                if ($originHost !== '' && $originHost === $urlHost && $element->kind === 'link') {
                    $path = parse_url($url, PHP_URL_PATH);
                    if (is_string($path) && $this->looksLikeNavPath($path)) {
                        return [
                            'excluded' => true,
                            'rule' => 'same-site chrome duplicate',
                            'reason' => "link to same-site nav path '{$path}' — chrome duplicate of the rebuilt nav",
                        ];
                    }
                }
            }
        }

        if ($element->kind === 'prose' || $element->kind === 'heading') {
            $lower = mb_strtolower(trim($element->content));
            if ($lower === '') {
                return $notExcluded;
            }
            // RULE 4 — legal boilerplate whole-line
            foreach (self::LEGAL_BOILERPLATE_NEEDLES as $phrase) {
                if ($lower === $phrase) {
                    return [
                        'excluded' => true,
                        'rule' => 'legal / unsubscribe boilerplate',
                        'reason' => "line exactly matches boilerplate phrase '{$phrase}'",
                    ];
                }
            }

            // RULE 5 — stale live-widget artefact
            $normalised = $this->normaliseForWidgetMatch($element->content);
            if ($normalised !== '') {
                foreach (self::WIDGET_ARTEFACT_PATTERNS as $pattern) {
                    if (preg_match($pattern, $normalised) === 1) {
                        return [
                            'excluded' => true,
                            'rule' => 'stale live-widget artefact',
                            'reason' => "source-site widget chrome captured as static text (no content value); matched '{$normalised}'",
                        ];
                    }
                }
            }
        }

        return $notExcluded;
    }

    // Whole-line normalisation used for widget-artefact matching only.
    // Strips markdown emphasis so "Days**0**" collapses to "days 0"
    // and matches the countdown pattern. Also strips leading/trailing
    // Unicode decorator glyphs (arrows, geometric shapes, media
    // symbols) so "←StopPlay→" collapses to "stopplay". Deliberately
    // narrow: only OUTER trimming — a real sentence with an arrow in
    // the middle ("Registration → Sign up here!") keeps the arrow and
    // will NOT match any whole-line pattern.
    private function normaliseForWidgetMatch(string $s): string
    {
        $s = str_replace(['**', '__', '*', '_'], ['', '', '', ''], $s);
        // Trim outer whitespace + decorator glyphs (arrows / media
        // symbols / geometric shapes). Ranges cover the specific
        // Unicode blocks that show up wrapping SE widget chrome —
        // NOT punctuation or letters.
        $s = preg_replace('/^[\s\x{2190}-\x{21FF}\x{25A0}-\x{25FF}\x{23E9}-\x{23FA}\x{2B05}-\x{2B07}]+/u', '', $s) ?? $s;
        $s = preg_replace('/[\s\x{2190}-\x{21FF}\x{25A0}-\x{25FF}\x{23E9}-\x{23FA}\x{2B05}-\x{2B07}]+$/u', '', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return mb_strtolower($s);
    }

    /**
     * @return array<int, array{rule: string, description: string}>
     */
    public function ruleSummary(): array
    {
        return [
            [
                'rule' => 'SE-platform chrome URL',
                'description' => 'Links / images / documents pointing at SportsEngine platform infrastructure (app stores, /solutions/, /user_sessions, sportsengine-prelive.com, dibs). Rebuilt site must have zero live SE dependency.',
            ],
            [
                'rule' => 'skip-nav / anchor-only URL',
                'description' => 'Page-internal anchors like #yieldContent — accessibility chrome, not content.',
            ],
            [
                'rule' => 'same-site chrome duplicate',
                'description' => 'Links back to the same site\'s nav-managed pages (/home, /aboutus, /parents). The rebuild carries its own nav; per-page repetitions of that nav are shared header/footer chrome.',
            ],
            [
                'rule' => 'legal / unsubscribe boilerplate',
                'description' => 'Whole-line prose that exactly matches a small closed list of legal boilerplate phrases (Unsubscribe, Privacy Policy, Terms of Service).',
            ],
            [
                'rule' => 'stale live-widget artefact',
                'description' => 'Whole-line prose that is source-site widget chrome — standalone countdown unit labels ("Days", "Hours", "Minutes", "Seconds"), decorated numeric forms ("Days**0**", "**0** Days"), bare timestamp fragments (00:00), and video-player chrome ("StopPlay", "About JW Player…"). A sentence that merely mentions a time or "hours" is org content and does NOT match.',
            ],
        ];
    }

    private function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host)) {
            return '';
        }
        $host = mb_strtolower($host);

        // Strip a leading `www.` so nav duplicates on both `www.` and
        // apex forms are equivalent.
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private function looksLikeNavPath(string $path): bool
    {
        $trim = trim($path, '/');
        if ($trim === '') {
            return true;
        }

        // A single-segment path with no query is a strong nav-duplicate
        // signal. Multi-segment paths (`/page/show/…`, article paths,
        // asset URLs) are NOT chrome — they're real page destinations.
        return ! str_contains($trim, '/');
    }
}
