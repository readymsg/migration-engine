<?php

declare(strict_types=1);

namespace App\Services\Coverage;

use App\Data\SourceElement;

// Deterministic element extractor for scraped markdown.
//
// Returns:
//   - `elements`: the FLAT list of discrete SourceElement records — the
//                 atoms the coverage reconciler classifies as CAPTURED,
//                 SUPERSEDED, or DROPPED. This is the primary output.
//   - `counts`  : per-kind tallies (derived from `elements`) — used by
//                 the report to print the "source elements counted"
//                 breakdown per page.
//   - `samples` : ≤ 3 sample snippets per kind for the counts breakdown
//                 table (a first-glance sanity check).
//
// Kinds:
//   heading | prose | image | link | document | embed | contact_detail | table
//
// Deliberately naive — this is a demo-artifact tool, not a lint pass.
// The point is to give the reconciler REAL atoms to match against the
// rebuilt page. If an element is ambiguous, we err on the side of
// counting it so the failure channel has a chance to fire.
final class SourceElementCounter
{
    /**
     * @return array{
     *   elements: array<int, SourceElement>,
     *   counts: array<string, int>,
     *   samples: array<string, array<int, string>>,
     *   total: int,
     * }
     */
    public function count(string $markdown): array
    {
        /** @var array<int, SourceElement> $elements */
        $elements = [];

        // Normalize backslash-escaping Firecrawl injects (`\*\*`, `\-` etc.)
        $normalized = str_replace(['\\*', '\\_', '\\-'], ['*', '_', '-'], $markdown);

        // --- images ---------------------------------------------------
        if (preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/', $normalized, $imgMatches)) {
            foreach ($imgMatches[2] as $i => $src) {
                $src = trim($src);
                if ($src === '') {
                    continue;
                }
                $elements[] = new SourceElement(
                    kind: 'image',
                    content: $src,
                    snippet: $imgMatches[0][$i],
                );
            }
        }

        // Strip images from a working copy so [](...) link regex doesn't
        // double-count them.
        $noImages = preg_replace('/!\[[^\]]*\]\([^)]+\)/', '', $normalized) ?? $normalized;

        // --- links (deliberately after image strip) -------------------
        if (preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $noImages, $linkMatches)) {
            foreach ($linkMatches[0] as $i => $whole) {
                $href = trim($linkMatches[2][$i] ?? '');
                if ($href === '') {
                    continue;
                }
                // skip-nav anchors — noise
                if (str_contains($href, '#yieldContent') || str_contains($href, '#skip')) {
                    continue;
                }
                // A mailto or phone-shaped href is a contact detail;
                // count it as such (not as a plain link) so the
                // reconciler matches on the email/phone atom directly.
                if (str_starts_with(mb_strtolower($href), 'mailto:')) {
                    $email = trim(substr($href, strlen('mailto:')));
                    $elements[] = new SourceElement(
                        kind: 'contact_detail',
                        content: $email,
                        snippet: $whole,
                    );

                    continue;
                }
                if (str_starts_with(mb_strtolower($href), 'tel:')) {
                    $phone = trim(substr($href, strlen('tel:')));
                    $elements[] = new SourceElement(
                        kind: 'contact_detail',
                        content: $phone,
                        snippet: $whole,
                    );

                    continue;
                }
                if (preg_match('/\.(pdf|docx?|xlsx?|pptx?)(\?|$)/i', $href)) {
                    $elements[] = new SourceElement(
                        kind: 'document',
                        content: $href,
                        snippet: $whole,
                    );

                    continue;
                }
                $elements[] = new SourceElement(
                    kind: 'link',
                    content: $href,
                    snippet: $whole,
                );
            }
        }

        // --- phone-shaped contacts (bare in prose) --------------------
        // Look for common US phone patterns: (513) 555-1234, 513-555-1234
        if (preg_match_all('/\b(?:\(\d{3}\)\s*|\d{3}[-.\s])\d{3}[-.\s]\d{4}\b/', $noImages, $phoneMatches)) {
            foreach ($phoneMatches[0] as $p) {
                $elements[] = new SourceElement(
                    kind: 'contact_detail',
                    content: $p,
                    snippet: $p,
                );
            }
        }

        // --- embeds --------------------------------------------------
        foreach (['<iframe', '<video', 'youtube.com/watch', 'youtube.com/embed', 'youtu.be/', 'vimeo.com/'] as $needle) {
            $n = substr_count($normalized, $needle);
            for ($i = 0; $i < $n; $i++) {
                $elements[] = new SourceElement(
                    kind: 'embed',
                    content: $needle,
                    snippet: $needle,
                );
            }
        }

        // --- tables --------------------------------------------------
        $lines = preg_split('/\r?\n/', $normalized) ?: [];
        $currentTableCells = null;
        foreach ($lines as $line) {
            $trim = trim($line);
            $looksTable = $trim !== '' && str_starts_with($trim, '|') && str_ends_with($trim, '|');
            if ($looksTable) {
                $currentTableCells ??= [];
                // extract cell content
                $inner = trim($trim, '|');
                foreach (explode('|', $inner) as $cell) {
                    $cellTrim = trim($cell);
                    if ($cellTrim === '' || preg_match('/^-+$/', $cellTrim)) {
                        continue;
                    }
                    $currentTableCells[] = $cellTrim;
                }
            } else {
                if ($currentTableCells !== null && $currentTableCells !== []) {
                    $joined = implode(' | ', $currentTableCells);
                    $elements[] = new SourceElement(
                        kind: 'table',
                        content: $joined,
                        snippet: mb_substr($joined, 0, 140),
                    );
                }
                $currentTableCells = null;
            }
        }
        if ($currentTableCells !== null && $currentTableCells !== []) {
            $joined = implode(' | ', $currentTableCells);
            $elements[] = new SourceElement(
                kind: 'table',
                content: $joined,
                snippet: mb_substr($joined, 0, 140),
            );
        }

        // --- headings + prose (line pass) ----------------------------
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            if (preg_match('/^#{1,6}\s+(.*)$/', $trim, $m) === 1) {
                $text = trim($m[1]);
                if ($text === '') {
                    continue;
                }
                $elements[] = new SourceElement(
                    kind: 'heading',
                    content: $text,
                    snippet: $trim,
                );

                continue;
            }
            // list item — count under prose (it's still narrative content)
            if (preg_match('/^([-*+]|\d+\.)\s+(.*)$/', $trim, $m) === 1) {
                $text = trim($m[2]);
                if ($text === '') {
                    continue;
                }
                // An image-only bullet (`- ![](url)` or `- [![](url)](href)`)
                // has already been counted as `image` (and, in the
                // wrapper-link case, as `link`) in the passes above.
                // Do NOT also count it as prose — that double-count
                // was inflating the DROPPED total by ~70 items on
                // image-heavy pages like tbirdhoops Home.
                if (preg_match('/^!\[[^\]]*\]\([^)]+\)$/', $text)) {
                    continue;
                }
                if (preg_match('/^\[!\[[^\]]*\]\([^)]+\)\]\([^)]+\)$/', $text)) {
                    continue;
                }
                $elements[] = new SourceElement(
                    kind: 'prose',
                    content: $text,
                    snippet: mb_substr($text, 0, 140),
                );

                continue;
            }
            // table row — already counted at table level
            if (str_starts_with($trim, '|')) {
                continue;
            }
            // an image-only line (image already counted) — skip prose
            if (preg_match('/^!\[[^\]]*\]\([^)]+\)$/', $trim)) {
                continue;
            }
            // link-only? — already counted as link, don't double count
            if (preg_match('/^\[[^\]]+\]\([^)]+\)$/', $trim)) {
                continue;
            }
            $elements[] = new SourceElement(
                kind: 'prose',
                content: $trim,
                snippet: mb_substr($trim, 0, 140),
            );
        }

        // Derive counts + samples from the flat elements list.
        /** @var array<string, int> $counts */
        $counts = [
            'heading' => 0,
            'prose' => 0,
            'image' => 0,
            'link' => 0,
            'document' => 0,
            'embed' => 0,
            'contact_detail' => 0,
            'table' => 0,
        ];
        /** @var array<string, array<int, string>> $samples */
        $samples = array_fill_keys(array_keys($counts), []);
        foreach ($elements as $el) {
            $counts[$el->kind] = ($counts[$el->kind] ?? 0) + 1;
            if (count($samples[$el->kind] ?? []) < 3) {
                $samples[$el->kind][] = $el->snippet;
            }
        }

        return [
            'elements' => $elements,
            'counts' => $counts,
            'samples' => $samples,
            'total' => count($elements),
        ];
    }
}
