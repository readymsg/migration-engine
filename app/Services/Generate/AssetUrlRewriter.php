<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\AssemblyResult;
use App\Data\AssetRef;
use App\Data\Manifest;
use App\Data\PuckOutput;
use App\Data\ScrubIssue;
use App\Data\ScrubKind;
use Spatie\LaravelData\DataCollection;

// Deterministic post-assembly SE-CDN URL rewriter. Runs after
// GalleryFiller and before draft-landing.
//
// WHY THIS EXISTS: INGEST rehosts every sportngin.com / assets.ngin.com /
// app-assets*.sportngin.com asset to S3 via SeCdnRehoster and records
// each AssetRef.source_url → s3_key on the Manifest. But block-fill
// (Sonnet) never sees that map — it lifts image URLs verbatim from
// body_markdown. So the emitted Puck has LIVE cdn*.sportngin.com URLs.
// CLAUDE.md:151 flags this as the deferred GENERATE-side rewrite.
//
// FIX: walk every PuckOutput's props recursively (nested objects,
// arrays, and slot content included). For any URL whose host matches
// an SE-CDN pattern:
//   - look it up by normalized-URL equality against
//     Manifest.asset_refs[].source_url
//   - swap to AssetRef.s3_key on match, record ScrubKind::AssetUrlRewritten
//   - if NO match, leave the URL live AND record
//     ScrubKind::AssetRehostMissing with the URL + block index. A live
//     SE dependency MUST NEVER be silent.
//
// URL normalization for matching: strip query string + fragment,
// lowercase scheme + host, preserve path. Same normalization SeCdnRehoster
// uses for its canonical() so both sides converge.
final class AssetUrlRewriter
{
    /** @var array<int, string> lowercased host suffixes for SE-CDN detection */
    private const SE_CDN_HOST_SUFFIXES = [
        '.sportngin.com',      // cdn1-4, app-assets1-3, etc.
        '.sportsengine.com',   // rare, but present in some app-managed asset paths
    ];

    /** @var array<int, string> exact-match hosts (no dot prefix) */
    private const SE_CDN_HOSTS = [
        'sportngin.com',
        'assets.ngin.com',
    ];

    public function run(AssemblyResult $assembly, Manifest $manifest): AssemblyResult
    {
        $urlToS3 = $this->buildUrlToS3Map($manifest);

        /** @var array<int, PuckOutput> $newPages */
        $newPages = [];
        $scrubs = $assembly->scrub_issues_by_slug;

        /** @var array<int, PuckOutput> $pages */
        $pages = $assembly->pages->items();
        foreach ($pages as $page) {
            /** @var array<int, ScrubIssue> $pageIssues */
            $pageIssues = $scrubs[$page->page_slug] ?? [];

            $rewritten = 0;
            $missing = 0;
            $newContent = [];
            foreach ($page->content as $blockIndex => $block) {
                if (! is_array($block)) {
                    $newContent[] = $block;

                    continue;
                }
                [$rewrittenBlock, $blockRewrites, $blockMissing] = $this->walkBlock(
                    $block,
                    (int) $blockIndex,
                    $urlToS3,
                    $pageIssues,
                );
                $newContent[] = $rewrittenBlock;
                $rewritten += $blockRewrites;
                $missing += $blockMissing;
            }

            $newPages[] = new PuckOutput(
                page_slug: $page->page_slug,
                content: $newContent,
                root: $page->root,
                zones: $page->zones,
            );
            if ($pageIssues !== []) {
                $scrubs[$page->page_slug] = $pageIssues;
            }
            // Only track counts — the individual events are already
            // recorded in $pageIssues by walkBlock.
            unset($rewritten, $missing);
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
     * @return array<string, string> normalized-source-url → s3_key
     */
    private function buildUrlToS3Map(Manifest $manifest): array
    {
        /** @var array<string, string> $out */
        $out = [];
        /** @var array<int, AssetRef> $refs */
        $refs = $manifest->asset_refs->items();
        foreach ($refs as $ref) {
            if ($ref->source_url === null || $ref->source_url === '') {
                continue;
            }
            $normalised = $this->normaliseUrl($ref->source_url);
            if ($normalised === '') {
                continue;
            }
            // First-writer-wins: two AssetRefs for the same source URL
            // would be a bug upstream (SeCdnRehoster canonicalises
            // before uploading), but the guard keeps this pass safe.
            $out[$normalised] ??= $ref->s3_key;
        }

        return $out;
    }

    /**
     * Walk one top-level block and its nested structure. Mutates a
     * copy of the block; returns [rewrittenBlock, rewriteCount,
     * missingCount]. Scrub issues are appended to $pageIssues.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $urlToS3
     * @param  array<int, ScrubIssue>  $pageIssues
     * @return array{0: array<string, mixed>, 1: int, 2: int}
     */
    private function walkBlock(array $block, int $blockIndex, array $urlToS3, array &$pageIssues): array
    {
        $rewrites = 0;
        $missing = 0;
        $componentType = is_string($block['type'] ?? null) ? $block['type'] : '';
        $props = is_array($block['props'] ?? null) ? $block['props'] : [];
        $props = $this->walkValue($props, $componentType, $blockIndex, $urlToS3, $pageIssues, $rewrites, $missing);
        $block['props'] = $props;

        return [$block, $rewrites, $missing];
    }

    /**
     * Recurse into any value shape (scalar / array / nested object).
     * Rewrites strings in place; returns the (possibly-modified) value.
     *
     * @param  array<string, string>  $urlToS3
     * @param  array<int, ScrubIssue>  $pageIssues
     */
    private function walkValue(
        mixed $value,
        string $componentType,
        int $blockIndex,
        array $urlToS3,
        array &$pageIssues,
        int &$rewrites,
        int &$missing,
    ): mixed {
        if (is_string($value)) {
            return $this->rewriteString($value, $componentType, $blockIndex, $urlToS3, $pageIssues, $rewrites, $missing);
        }
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $k => $v) {
            $value[$k] = $this->walkValue($v, $componentType, $blockIndex, $urlToS3, $pageIssues, $rewrites, $missing);
        }

        return $value;
    }

    /**
     * Given a scalar string, decide whether it holds an SE-CDN URL
     * (whole-string OR embedded — e.g. an image URL inside a markdown
     * body-copy field). Whole-string URL props (src, href,
     * background_image) get exact-swap. Embedded URLs inside text
     * bodies get in-place replacement of the matched substring.
     *
     * @param  array<string, string>  $urlToS3
     * @param  array<int, ScrubIssue>  $pageIssues
     */
    private function rewriteString(
        string $value,
        string $componentType,
        int $blockIndex,
        array $urlToS3,
        array &$pageIssues,
        int &$rewrites,
        int &$missing,
    ): string {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $value;
        }

        // Fast path: whole-string is an SE-CDN URL.
        if ($this->isSeCdnUrl($trimmed)) {
            $normalised = $this->normaliseUrl($trimmed);
            if (isset($urlToS3[$normalised])) {
                $s3 = $urlToS3[$normalised];
                $pageIssues[] = new ScrubIssue(
                    block_index: $blockIndex,
                    component_type: $componentType,
                    kind: ScrubKind::AssetUrlRewritten,
                    reason: 'rewrote live SE-CDN URL to rehosted S3 key',
                    dropped_content_summary: "{$trimmed} → {$s3}",
                );
                $rewrites++;

                return $s3;
            }
            $pageIssues[] = new ScrubIssue(
                block_index: $blockIndex,
                component_type: $componentType,
                kind: ScrubKind::AssetRehostMissing,
                reason: 'live SE-CDN URL left intact — no matching AssetRef in Manifest.asset_refs. Rebuilt site retains a live SE dependency for this asset until INGEST rehosts it.',
                dropped_content_summary: $trimmed,
            );
            $missing++;

            return $value;
        }

        // Slow path: string may embed one or more SE-CDN URLs (markdown
        // body text, HTML fragments). Extract + replace each match
        // in-place. Handles markdown image / link syntax where the URL
        // is inside `(...)`.
        if (! $this->stringHasSeCdnUrl($value)) {
            return $value;
        }
        $rewritten = preg_replace_callback(
            '#https?://[^\s"\'<>()\[\]]+#i',
            function (array $m) use ($componentType, $blockIndex, $urlToS3, &$pageIssues, &$rewrites, &$missing) {
                $url = rtrim($m[0], '.,;:!?');
                if (! $this->isSeCdnUrl($url)) {
                    return $m[0];
                }
                $normalised = $this->normaliseUrl($url);
                if (isset($urlToS3[$normalised])) {
                    $s3 = $urlToS3[$normalised];
                    $pageIssues[] = new ScrubIssue(
                        block_index: $blockIndex,
                        component_type: $componentType,
                        kind: ScrubKind::AssetUrlRewritten,
                        reason: 'rewrote embedded SE-CDN URL to rehosted S3 key',
                        dropped_content_summary: "{$url} → {$s3}",
                    );
                    $rewrites++;

                    // Preserve any trailing punctuation from the raw match.
                    return $s3.substr($m[0], strlen($url));
                }
                $pageIssues[] = new ScrubIssue(
                    block_index: $blockIndex,
                    component_type: $componentType,
                    kind: ScrubKind::AssetRehostMissing,
                    reason: 'live SE-CDN URL embedded in a text field, left intact — no matching AssetRef.',
                    dropped_content_summary: $url,
                );
                $missing++;

                return $m[0];
            },
            $value,
        );

        return is_string($rewritten) ? $rewritten : $value;
    }

    private function isSeCdnUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }
        $host = mb_strtolower($host);
        if (in_array($host, self::SE_CDN_HOSTS, true)) {
            return true;
        }
        foreach (self::SE_CDN_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function stringHasSeCdnUrl(string $value): bool
    {
        // Cheap prefilter before the more expensive regex + parse cycle.
        $lower = mb_strtolower($value);

        return str_contains($lower, 'sportngin.com')
            || str_contains($lower, 'assets.ngin.com')
            || str_contains($lower, 'sportsengine.com');
    }

    // Same shape as SeCdnRehoster::canonical(): strip query + fragment,
    // lowercase scheme + host, preserve path.
    private function normaliseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $url = substr($url, 0, $hash);
        }
        $q = strpos($url, '?');
        if ($q !== false) {
            $url = substr($url, 0, $q);
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return mb_strtolower($url);
        }
        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? mb_strtolower($parts['scheme']) : 'https';
        $host = mb_strtolower((string) $parts['host']);
        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '';

        return $scheme.'://'.$host.$path;
    }
}
