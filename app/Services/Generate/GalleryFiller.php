<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\AssemblyResult;
use App\Data\PuckOutput;
use App\Data\ScrubIssue;
use App\Data\ScrubKind;
use Spatie\LaravelData\DataCollection;

// Deterministic post-assembly gallery back-fill. Runs after the
// SePlatformBlockScrubber and before draft-landing.
//
// WHY THIS EXISTS: block-fill (Sonnet) silently truncates gallery
// image lists. On the tbirdhoops fixture, 8 of 9 source galleries
// received 1 image out of 4-14 present in source, with no scrub
// issue and no lowered confidence. The IR pass DID design gallery
// blocks (source_briefs read "Gallery for X photo set"), so this
// is loss inside block-fill's per-block render step, not upstream.
//
// FIX: for each Puck block on a page that LOOKS like a truncated
// gallery, look for a matching source gallery in the page markdown
// (matched by heading text). If found, replace the truncated
// Columns block with a native Gallery block carrying EVERY image
// from the source list.
//
// FAITHFUL-REBUILD DISCIPLINE: an image that can't be placed is a
// RECORDED failure, never a silent omission. Two scrub-kind events:
//   - GalleryFilled       — informational: N images added to block.
//   - GalleryFillFailure  — visible failure: source gallery had no
//                           matching target and couldn't be inserted
//                           cleanly; N images are missing.
final class GalleryFiller
{
    /**
     * @param  array<string, string>  $slugToMarkdown  page_slug → raw source markdown (from ContentLoader or the scrapes disk)
     */
    public function run(AssemblyResult $result, array $slugToMarkdown): AssemblyResult
    {
        /** @var array<int, PuckOutput> $pages */
        $pages = $result->pages->items();
        $scrubIssues = $result->scrub_issues_by_slug;

        /** @var array<int, PuckOutput> $newPages */
        $newPages = [];
        foreach ($pages as $page) {
            $markdown = $slugToMarkdown[$page->page_slug] ?? '';
            if ($markdown === '') {
                $newPages[] = $page;

                continue;
            }
            $sourceGalleries = $this->parseSourceGalleries($markdown);
            if ($sourceGalleries === []) {
                $newPages[] = $page;

                continue;
            }

            /** @var array<int, ScrubIssue> $pageIssues */
            $pageIssues = $scrubIssues[$page->page_slug] ?? [];
            $updatedPage = $this->fillOnePage($page, $sourceGalleries, $pageIssues);
            $newPages[] = $updatedPage['page'];
            if ($updatedPage['issues'] !== []) {
                $scrubIssues[$page->page_slug] = $updatedPage['issues'];
            }
        }

        return new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, $newPages),
            failures: $result->failures,
            block_issues_by_slug: $result->block_issues_by_slug,
            status: $result->status,
            style_brief: $result->style_brief,
            scrub_issues_by_slug: $scrubIssues,
        );
    }

    /**
     * @param  array<int, array{title: string, items: array<int, array{src: string, alt: string}>}>  $sourceGalleries
     * @param  array<int, ScrubIssue>  $existingIssues
     * @return array{page: PuckOutput, issues: array<int, ScrubIssue>}
     */
    private function fillOnePage(PuckOutput $page, array $sourceGalleries, array $existingIssues): array
    {
        $content = $page->content;
        /** @var array<int, ScrubIssue> $issues */
        $issues = $existingIssues;
        /** @var array<int, bool> $usedSourceIndex */
        $usedSourceIndex = [];

        // First pass — mutate blocks that look like galleries and have
        // a source-title match (heading nested inside the Columns).
        foreach ($content as $blockIndex => $block) {
            if (! is_array($block)) {
                continue;
            }
            if (! $this->looksLikeGallery($block)) {
                continue;
            }
            $blockTitle = $this->extractNestedHeadingText($block);
            $matchIndex = $this->findSourceGalleryByTitle($blockTitle, $sourceGalleries, $usedSourceIndex);
            if ($matchIndex === null) {
                continue;
            }
            $gallery = $sourceGalleries[$matchIndex];
            $usedSourceIndex[$matchIndex] = true;
            $existingImages = $this->collectImageSrcs($block);
            $before = count($existingImages);
            $target = count($gallery['items']);
            if ($before >= $target) {
                // Block already carries every source image — no fill
                // needed. Leave the block shape alone; block-fill got
                // this one right.
                continue;
            }
            $content[$blockIndex] = $this->buildGalleryBlock($gallery);
            $issues[] = new ScrubIssue(
                block_index: (int) $blockIndex,
                component_type: 'Gallery',
                kind: ScrubKind::GalleryFilled,
                reason: "gallery back-filled from source markdown (block-fill emitted {$before}/{$target} images)",
                dropped_content_summary: sprintf(
                    'title="%s"; added %d images from source (was %d, now %d)',
                    $gallery['title'],
                    $target - $before,
                    $before,
                    $target,
                ),
            );
        }

        // Second pass — source galleries with no matching Puck block.
        // Append them at the end of the content array as fresh Gallery
        // blocks, and record informational scrub entries. If a gallery
        // has zero images we can't build a valid block; that's a
        // failure and gets a GalleryFillFailure entry with no block
        // insertion.
        foreach ($sourceGalleries as $i => $gallery) {
            if (isset($usedSourceIndex[$i])) {
                continue;
            }
            if ($gallery['items'] === []) {
                $issues[] = new ScrubIssue(
                    block_index: count($content),
                    component_type: 'Gallery',
                    kind: ScrubKind::GalleryFillFailure,
                    reason: 'source gallery has no image URLs to place',
                    dropped_content_summary: sprintf('title="%s" (0 images)', $gallery['title']),
                );

                continue;
            }
            $content[] = $this->buildGalleryBlock($gallery);
            $issues[] = new ScrubIssue(
                block_index: count($content) - 1,
                component_type: 'Gallery',
                kind: ScrubKind::GalleryFilled,
                reason: 'source gallery had no matching Puck block; inserted as new Gallery',
                dropped_content_summary: sprintf(
                    'title="%s"; inserted %d images from source',
                    $gallery['title'],
                    count($gallery['items']),
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
     * @param  array<string, mixed>  $block
     */
    private function looksLikeGallery(array $block): bool
    {
        if (($block['type'] ?? null) !== 'Columns') {
            return false;
        }
        $columns = $block['props']['columns'] ?? [];
        if (! is_array($columns) || $columns === []) {
            return false;
        }
        $imageCount = 0;
        $childCount = 0;
        $headingCount = 0;
        foreach ($columns as $col) {
            if (! is_array($col)) {
                continue;
            }
            $children = is_array($col['children'] ?? null) ? $col['children'] : [];
            foreach ($children as $child) {
                if (! is_array($child)) {
                    continue;
                }
                $childCount++;
                $ct = $child['type'] ?? null;
                if ($ct === 'Image') {
                    $imageCount++;
                } elseif ($ct === 'Heading') {
                    $headingCount++;
                }
            }
        }
        // A gallery-shaped Columns: at least one Image, and every child
        // is either an Image or (at most one) Heading acting as the
        // group title. No Cards, no Text — those indicate a genuine
        // content Columns layout, not a gallery.
        if ($imageCount === 0) {
            return false;
        }

        return $imageCount + $headingCount === $childCount && $headingCount <= 1;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function extractNestedHeadingText(array $block): ?string
    {
        $columns = $block['props']['columns'] ?? [];
        if (! is_array($columns)) {
            return null;
        }
        foreach ($columns as $col) {
            $children = is_array($col) && is_array($col['children'] ?? null) ? $col['children'] : [];
            foreach ($children as $child) {
                if (is_array($child) && ($child['type'] ?? null) === 'Heading') {
                    $t = $child['props']['text'] ?? null;
                    if (is_string($t) && $t !== '') {
                        return $t;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{title: string, items: array<int, array{src: string, alt: string}>}>  $galleries
     * @param  array<int, bool>  $used
     */
    private function findSourceGalleryByTitle(?string $blockTitle, array $galleries, array $used): ?int
    {
        if ($blockTitle === null) {
            return null;
        }
        $target = $this->normalizeTitle($blockTitle);
        if ($target === '') {
            return null;
        }
        foreach ($galleries as $i => $g) {
            if (isset($used[$i])) {
                continue;
            }
            $candidate = $this->normalizeTitle($g['title']);
            if ($candidate === '') {
                continue;
            }
            if ($candidate === $target) {
                return $i;
            }
            if (str_contains($candidate, $target) || str_contains($target, $candidate)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<int, string>
     */
    private function collectImageSrcs(array $block): array
    {
        /** @var array<int, string> $out */
        $out = [];
        $columns = $block['props']['columns'] ?? [];
        if (! is_array($columns)) {
            return $out;
        }
        foreach ($columns as $col) {
            $children = is_array($col) && is_array($col['children'] ?? null) ? $col['children'] : [];
            foreach ($children as $child) {
                if (is_array($child) && ($child['type'] ?? null) === 'Image') {
                    $src = $child['props']['src'] ?? null;
                    if (is_string($src) && $src !== '') {
                        $out[] = $src;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  array{title: string, items: array<int, array{src: string, alt: string}>}  $gallery
     * @return array<string, mixed>
     */
    private function buildGalleryBlock(array $gallery): array
    {
        $items = [];
        foreach ($gallery['items'] as $it) {
            $items[] = [
                'src' => $it['src'],
                'alt' => $it['alt'] !== '' ? $it['alt'] : $gallery['title'],
            ];
        }

        return [
            'type' => 'Gallery',
            'props' => [
                'title' => $gallery['title'],
                'items' => $items,
            ],
        ];
    }

    /**
     * @return array<int, array{title: string, items: array<int, array{src: string, alt: string}>}>
     */
    private function parseSourceGalleries(string $markdown): array
    {
        // Normalize backslash-escapes Firecrawl injects.
        $normalized = str_replace(['\\*', '\\_', '\\-', '\\('], ['*', '_', '-', '('], $markdown);
        $lines = preg_split('/\r?\n/', $normalized) ?: [];

        /** @var array<int, array{title: string, items: array<int, array{src: string, alt: string}>}> $out */
        $out = [];
        $current = null;
        foreach ($lines as $line) {
            $trim = trim($line);
            $isHeading = preg_match('/^#{1,6}\s+(.+)$/', $trim, $m) === 1;
            $isImageBullet = preg_match('/^-\s+!\[\s*(.*?)\s*\]\(\s*(https?:\/\/[^\s)]+)\s*\)/', $trim, $mi) === 1;
            $isTopBullet = str_starts_with($trim, '-') || preg_match('/^\d+\./', $trim) === 1;

            if ($isHeading) {
                if ($current !== null) {
                    $out[] = $current;
                    $current = null;
                }
                $current = ['title' => trim($m[1]), 'items' => []];

                continue;
            }
            if ($current !== null && $isImageBullet) {
                $current['items'][] = ['src' => $mi[2], 'alt' => $mi[1]];

                continue;
            }
            // Non-image content between the heading and its images
            // breaks the gallery. Only accept a strictly-image-bullet
            // group directly under the heading (with blank lines OK).
            if ($current !== null && $trim !== '' && ! $isImageBullet) {
                // If the heading was followed by non-image content and
                // NO images yet, drop the buffer — it wasn't a gallery
                // section at all. If it DID accumulate images and then
                // hit non-image content, commit and reset.
                if ($current['items'] !== []) {
                    $out[] = $current;
                    $current = null;

                    continue;
                }
                if ($isTopBullet) {
                    // A bulleted-text list under this heading — not a
                    // gallery. Discard.
                    $current = null;
                }
            }
        }
        if ($current !== null && $current['items'] !== []) {
            $out[] = $current;
        }

        // Only surface entries with at least one image (a bare heading
        // with no gallery is not a source gallery for our purposes).
        return array_values(array_filter($out, static fn (array $g) => $g['items'] !== []));
    }

    private function normalizeTitle(string $s): string
    {
        // Strip markdown decoration, unicode dashes, non-word noise.
        $s = str_replace(['\\*', '\\_', '\\-'], ['*', '_', '-'], $s);
        $s = strtr($s, [
            "\u{2013}" => '-',
            "\u{2014}" => '-',
            "\u{2018}" => "'",
            "\u{2019}" => "'",
            "\u{201C}" => '"',
            "\u{201D}" => '"',
            '&' => 'and',
        ]);
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', trim($s)) ?? $s;

        return mb_strtolower($s);
    }
}
