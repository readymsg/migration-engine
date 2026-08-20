<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\AssemblyResult;
use App\Data\PuckOutput;
use App\Data\ScrubIssue;
use App\Data\ScrubKind;
use Spatie\LaravelData\DataCollection;

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
    /** @var array<int, string> case-insensitive substrings that mark a banner-shape URL */
    private const BANNER_SHAPE_NEEDLES = [
        'site-banner',
        'site_banner',
        'siteheader',
        'site-header',
        'site_header',
        'banner_graphic',
        'banner-graphic',
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
     *   1. First candidate whose path/filename matches a banner-shape
     *      needle (deterministic, cheap, works offline).
     *   2. (Future) widest-aspect image via AssetRef dimensions —
     *      requires the Manifest hookup; not implemented in this
     *      offline-safe pass. Documented in the reason string when
     *      the resolver falls to tier 3 with dimension data absent.
     *   3. Keep the current pick (block-fill's first-image choice)
     *      OR the first source-markdown image if block-fill emitted
     *      nothing.
     *
     * @param  array<int, string>  $candidates
     * @return array{0: string, 1: string} [chosenUrl, reason]
     */
    private function pickBest(array $candidates, ?string $currentUrl): array
    {
        // Tier 1: banner-shape URL.
        foreach ($candidates as $c) {
            if ($this->looksLikeBanner($c)) {
                if ($c === $currentUrl) {
                    return [$c, 'kept block-fill pick — URL matches banner-shape rule'];
                }

                return [$c, 'replaced block-fill first-image pick with banner-shape candidate'];
            }
        }

        // Tier 2: widest-aspect. Requires image dimensions; not
        // available on offline paths and this pass is Manifest-free
        // by design. Documented as a known drop-through.

        // Tier 3: keep whatever we currently have (block-fill's first-
        // image pick), or the first source-markdown image if block-
        // fill emitted none.
        $fallback = $currentUrl !== null && $currentUrl !== '' ? $currentUrl : $candidates[0];
        $reason = $currentUrl !== null && $currentUrl !== ''
            ? 'kept block-fill first-image pick — no banner-shape signal in source'
            : 'picked first source-markdown image — block-fill emitted no background_image';

        return [$fallback, $reason];
    }

    private function looksLikeBanner(string $url): bool
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
}
