<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\AssemblyResult;
use App\Data\PuckOutput;
use App\Data\ScrubIssue;
use App\Data\ScrubKind;
use Illuminate\Support\Facades\Http;
use Spatie\LaravelData\DataCollection;
use Throwable;

// Deterministic post-assembly hero image resolver. Runs AFTER
// GalleryFiller and BEFORE AssetUrlRewriter — the resolver needs the
// ORIGINAL CDN URLs (filename hints) that AssetUrlRewriter later
// swaps for S3 keys.
//
// WHY THIS EXISTS: block-fill (Sonnet) picks the FIRST prominent
// image from body_markdown as Hero.background_image. On tbirdhoops
// that first image happens to be a season banner ("LTYB_site-banner"
// filename) — correct by coincidence. On a page whose first image is
// a news thumbnail or a coach photo, the Hero would inherit that
// instead. This resolver picks deliberately, in preference order:
//   1. banner-shape signal in the URL path or filename ("banner",
//      "header", "hero", "site-banner", "site_header")
//   2. widest-aspect image (requires AssetRef width/height — not
//      populated on offline paths, so falls through)
//   3. keep block-fill's current pick as fallback
//
// Every Hero block gets ONE ScrubKind::HeroImageChosen entry, always,
// so the decision — including "kept block-fill's pick" — is visible.
final class HeroImageResolver
{
    // Ranking needles. TWO tiers now, checked in order. Tier 1 is the
    // path where SportsEngine ACTUALLY stores site banner assets
    // (banner_graphic / banner-graphic). Tier 2 is inferred-shape
    // needles that catch filenames like LTYB_site-banner_large.jpg
    // living under a generic /photo/ path — often a body-image the
    // block-fill agent picked, sometimes the club's real banner, but
    // NEVER the canonical SE banner asset. A tier-1 hit ALWAYS
    // outranks a tier-2 hit even if tier-2 appears first in the
    // candidate list, because SE's own path convention is more
    // reliable than URL text matching.
    /** @var array<int, string> tier-1: SE's canonical banner asset paths — always prefer */
    private const BANNER_PATH_NEEDLES = [
        'banner_graphic',
        'banner-graphic',
    ];

    /** @var array<int, string> tier-2: inferred banner-shape signals in filename/path */
    private const BANNER_SHAPE_NEEDLES = [
        'site-banner',
        'site_banner',
        'siteheader',
        'site-header',
        'site_header',
        'homepage-banner',
        'hero-banner',
        '/banner/',
        '/header/',
        '/hero/',
    ];

    /**
     * @param  array<string, string>  $slugToMarkdown  page_slug → raw source markdown
     */
    public function run(AssemblyResult $assembly, array $slugToMarkdown): AssemblyResult
    {
        $scrubs = $assembly->scrub_issues_by_slug;

        /** @var array<int, PuckOutput> $newPages */
        $newPages = [];
        foreach ($assembly->pages->items() as $page) {
            $md = $slugToMarkdown[$page->page_slug] ?? '';
            /** @var array<int, ScrubIssue> $pageIssues */
            $pageIssues = $scrubs[$page->page_slug] ?? [];
            $updated = $this->resolveOnePage($page, $md, $pageIssues);
            $newPages[] = $updated['page'];
            if ($updated['issues'] !== []) {
                $scrubs[$page->page_slug] = $updated['issues'];
            }
        }

        return new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, $newPages),
            failures: $assembly->failures,
            block_issues_by_slug: $assembly->block_issues_by_slug,
            status: $assembly->status,
            style_brief: $assembly->style_brief,
            scrub_issues_by_slug: $scrubs,
        );
    }

    /**
     * @param  array<int, ScrubIssue>  $existingIssues
     * @return array{page: PuckOutput, issues: array<int, ScrubIssue>}
     */
    private function resolveOnePage(PuckOutput $page, string $markdown, array $existingIssues): array
    {
        $issues = $existingIssues;
        $content = $page->content;

        foreach ($content as $blockIndex => $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'Hero') {
                continue;
            }
            $currentUrl = $this->stringOrNull($block['props']['background_image'] ?? null);
            $candidates = $this->collectCandidates($markdown, $currentUrl);
            if ($candidates === []) {
                // Nothing to pick from — Hero has no background image
                // AND no images exist in the page's source markdown.
                // Record so the decision is visible.
                $issues[] = new ScrubIssue(
                    block_index: (int) $blockIndex,
                    component_type: 'Hero',
                    kind: ScrubKind::HeroImageChosen,
                    reason: 'no candidate hero image available (Hero.background_image empty and source has no images)',
                    dropped_content_summary: '(no candidates)',
                );

                continue;
            }
            [$picked, $reason] = $this->pickBest($candidates, $currentUrl);
            if ($picked !== $currentUrl) {
                // Replace block-fill's choice with the deliberate pick.
                $content[$blockIndex]['props']['background_image'] = $picked;
            }
            $issues[] = new ScrubIssue(
                block_index: (int) $blockIndex,
                component_type: 'Hero',
                kind: ScrubKind::HeroImageChosen,
                reason: $reason,
                dropped_content_summary: sprintf(
                    'picked=%s (block-fill had %s)',
                    $picked,
                    $currentUrl ?? '(none)',
                ),
            );

            // Probe the picked URL. If SE's CDN has deprovisioned the
            // asset (403 / 404 / gone), null out background_image so
            // Hero.tsx falls back to the solid-color treatment, and
            // record a visible HeroImageUnreachable finding. Skips
            // s3://-shaped and rehosted URLs (only probes live http(s)
            // sources) and swallows probe errors (network blip
            // shouldn't mark a working asset unreachable).
            if ($this->looksLikeLiveHttp($picked)) {
                $status = $this->probeHead($picked);
                if ($status !== null && ($status < 200 || $status >= 300)) {
                    $content[$blockIndex]['props']['background_image'] = null;
                    $issues[] = new ScrubIssue(
                        block_index: (int) $blockIndex,
                        component_type: 'Hero',
                        kind: ScrubKind::HeroImageUnreachable,
                        reason: "hero background_image is no longer available from the source CDN (HTTP {$status}) — asset rot on the source platform; Hero falls back to solid-color treatment",
                        dropped_content_summary: sprintf('url=%s http=%d', $picked, $status),
                    );
                }
            }
        }

        return [
            'page' => new PuckOutput(
                page_slug: $page->page_slug,
                content: $content,
                root: $page->root,
                zones: $page->zones,
            ),
            'issues' => $issues,
        ];
    }

    /**
     * Collect ordered candidate URLs from source markdown plus the
     * current block-fill pick. Preserves source order (needed for the
     * "first-image fallback" tier) and de-duplicates.
     *
     * @return array<int, string>
     */
    private function collectCandidates(string $markdown, ?string $currentUrl): array
    {
        /** @var array<string, true> $seen */
        $seen = [];
        /** @var array<int, string> $out */
        $out = [];

        if ($currentUrl !== null && $currentUrl !== '') {
            $seen[$currentUrl] = true;
            $out[] = $currentUrl;
        }

        if ($markdown !== '') {
            // Match ![alt](url) markdown images. Preserve document order.
            if (preg_match_all('/!\[[^\]]*\]\(([^)]+)\)/', $markdown, $m) > 0) {
                foreach ($m[1] as $raw) {
                    $url = trim($raw);
                    if ($url === '' || isset($seen[$url])) {
                        continue;
                    }
                    // Only consider http(s) URLs — skip data: URIs and
                    // relative refs we can't resolve.
                    if (! preg_match('#^https?://#i', $url)) {
                        continue;
                    }
                    $seen[$url] = true;
                    $out[] = $url;
                }
            }
        }

        return $out;
    }

    /**
     * Rank the candidates. In preference order:
     *   1. First candidate on a SE canonical banner asset path
     *      (`banner_graphic/` or `banner-graphic/`) — where
     *      SportsEngine actually stores site banners.
     *   2. Otherwise, first candidate whose path/filename matches an
     *      inferred banner-shape needle (site-banner, siteheader,
     *      hero-banner, /banner/, etc.). Weaker signal — often a
     *      body-image the block-fill agent picked whose filename
     *      happened to look banner-ish.
     *   3. (Future) widest-aspect image via AssetRef dimensions —
     *      not implemented in this offline-safe pass.
     *   4. Keep the current pick (block-fill's first-image choice)
     *      OR the first source-markdown image if block-fill emitted
     *      nothing.
     *
     * @param  array<int, string>  $candidates
     * @return array{0: string, 1: string} [chosenUrl, reason]
     */
    private function pickBest(array $candidates, ?string $currentUrl): array
    {
        // Tier 1: SE canonical banner asset path.
        foreach ($candidates as $c) {
            if ($this->matchesBannerPath($c)) {
                if ($c === $currentUrl) {
                    return [$c, 'kept block-fill pick — URL on SE canonical banner asset path (banner_graphic/)'];
                }

                return [$c, 'replaced block-fill pick with candidate on SE canonical banner asset path (banner_graphic/)'];
            }
        }

        // Tier 2: inferred banner-shape needle (weaker signal).
        foreach ($candidates as $c) {
            if ($this->matchesBannerShape($c)) {
                if ($c === $currentUrl) {
                    return [$c, 'kept block-fill pick — URL matches inferred banner-shape rule'];
                }

                return [$c, 'replaced block-fill first-image pick with inferred-banner-shape candidate'];
            }
        }

        // Tier 3: widest-aspect. Requires image dimensions; not
        // available on offline paths and this pass is Manifest-free
        // by design. Documented as a known drop-through.

        // Tier 4: keep whatever we currently have (block-fill's first-
        // image pick), or the first source-markdown image if block-
        // fill emitted none.
        $fallback = $currentUrl !== null && $currentUrl !== '' ? $currentUrl : $candidates[0];
        $reason = $currentUrl !== null && $currentUrl !== ''
            ? 'kept block-fill first-image pick — no banner-path or banner-shape signal in source'
            : 'picked first source-markdown image — block-fill emitted no background_image';

        return [$fallback, $reason];
    }

    private function matchesBannerPath(string $url): bool
    {
        $lower = mb_strtolower($url);
        foreach (self::BANNER_PATH_NEEDLES as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function matchesBannerShape(string $url): bool
    {
        $lower = mb_strtolower($url);
        foreach (self::BANNER_SHAPE_NEEDLES as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function stringOrNull(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    // Probes only live http(s) URLs (skips s3://, /preview-assets/,
    // and empty). This is deliberate — Manifest.asset_refs-derived
    // s3_keys are already-rehosted and resolve locally at serve time;
    // probing them would require going through the resolver route
    // which is preview-only. The probe exists specifically to catch
    // "source URL still points at a CDN asset that has 403'd".
    private function looksLikeLiveHttp(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1;
    }

    // HEAD probe with a short timeout. Swallows any exception so a
    // transient network blip doesn't mark a live asset unreachable;
    // returns null on error to signal "unable to determine — leave
    // the pick alone". Non-null return is the HTTP status.
    private function probeHead(string $url): ?int
    {
        try {
            $response = Http::timeout(5)->head($url);
        } catch (Throwable) {
            return null;
        }

        return $response->status();
    }
}
