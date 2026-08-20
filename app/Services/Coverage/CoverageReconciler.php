<?php

declare(strict_types=1);

namespace App\Services\Coverage;

use App\Data\ReconciledElement;
use App\Data\ScrubIssue;
use App\Data\SourceElement;

// Element-level coverage reconciler. Deterministic — NO LLM.
//
// For every SourceElement extracted from the scraped page markdown,
// decide which of FIVE outcomes applies:
//
//   CAPTURED    — element text/URL appears (normalized) in a content
//                 block's props.
//   SUPERSEDED  — element ISN'T in the rebuilt content, BUT is
//                 accounted for by (a) a Platform block on this page
//                 (data regenerates from TeamLinkt's own DB), OR (b)
//                 a scrub sidecar entry that dropped the containing
//                 block.
//   EXCLUDED    — element matches an ExclusionRules pattern (source-
//                 platform chrome — SE-prelive nav, widget artefacts,
//                 legal boilerplate). Reported separately; NOT counted
//                 in DROPPED.
//   OUT_OF_SCOPE — element matches an OutOfScopeRules pattern (product
//                 has deliberately scoped this content type OUT of
//                 this version — news article internals, board /
//                 contact directories, sponsor strips). Named because
//                 the eventual platform feature (NewsList, Executives,
//                 Sponsors) will own it. Reported separately; NOT
//                 counted in DROPPED.
//   DROPPED     — present in source, absent from the rebuild, nothing
//                 superseded / excluded / scoped-out accounts for it.
//                 THE FAILURE CHANNEL — what the report exists to
//                 make visible.
//
// The comparator is normalized substring (text) or normalized URL
// equality/prefix (urls). The intent is order-of-magnitude honesty,
// not a lint pass — false negatives (real elements incorrectly marked
// DROPPED) are ACCEPTED because a false 100% is worse than an honest
// 85%.
//
// Order — a captured element ALWAYS beats every deliberate-discard
// rule (rebuilt content survives even if it matched a boilerplate or
// scoping pattern by accident). Deliberate-discard rules fire only
// when the element would otherwise be DROPPED.
final class CoverageReconciler
{
    public function __construct(
        private readonly ExclusionRules $exclusions = new ExclusionRules,
        private readonly OutOfScopeRules $outOfScope = new OutOfScopeRules,
    ) {}

    /**
     * @param  array<int, SourceElement>  $elements
     * @param  array<string, mixed>  $puckPayload  the ConversionResult.page_map value: {content, root, zones}
     * @param  array<int, ScrubIssue|array<string, mixed>>  $scrubs  scrub sidecar entries for THIS page
     * @return array<int, ReconciledElement>
     */
    public function reconcile(
        array $elements,
        array $puckPayload,
        array $scrubs = [],
        ?string $sourceOrigin = null,
        string $pageTitle = '',
    ): array {
        $index = $this->buildBlockIndex($puckPayload);
        $scrubIndex = $this->buildScrubIndex($scrubs);
        $platformBlockType = $this->platformBlockTypeOnPage($puckPayload);

        /** @var array<int, ReconciledElement> $out */
        $out = [];
        foreach ($elements as $element) {
            $out[] = $this->reconcileOne(
                $element,
                $index,
                $scrubIndex,
                $platformBlockType,
                $sourceOrigin,
                $puckPayload,
                $pageTitle,
            );
        }

        return $out;
    }

    /**
     * @param  array{text: string, urls: array<int, string>}  $index
     * @param  array{text: string, urls: array<int, string>, kinds: array<int, string>}  $scrubIndex
     * @param  array<string, mixed>  $puckPayload
     */
    private function reconcileOne(
        SourceElement $element,
        array $index,
        array $scrubIndex,
        ?string $platformBlockType,
        ?string $sourceOrigin,
        array $puckPayload,
        string $pageTitle,
    ): ReconciledElement {
        $isTextKind = in_array($element->kind, ['heading', 'prose', 'table'], true);
        $isUrlKind = in_array($element->kind, ['image', 'link', 'document'], true);
        $isContact = $element->kind === 'contact_detail';

        // Try CAPTURED first — content survived in a content block.
        if ($isTextKind) {
            $needle = $this->normalizeText($element->content);
            if ($needle !== '' && $this->textMatches($needle, $index['text'])) {
                return new ReconciledElement(
                    source: $element,
                    disposition: 'captured',
                    reason: 'text found in a content block',
                    evidence: mb_substr($needle, 0, 80),
                );
            }
        }
        if ($isUrlKind) {
            $needle = $this->normalizeUrl($element->content);
            if ($needle !== '' && $this->matchUrl($needle, $index['urls'])) {
                return new ReconciledElement(
                    source: $element,
                    disposition: 'captured',
                    reason: 'url found in a content block',
                    evidence: $needle,
                );
            }
        }
        if ($isContact) {
            // Contact detail: match either the exact string in block URLs
            // (mailto: href) OR the raw string appearing in block text.
            $rawLower = mb_strtolower(trim($element->content));
            if ($rawLower !== '') {
                foreach ($index['urls'] as $blockUrl) {
                    if (str_contains(mb_strtolower($blockUrl), $rawLower)) {
                        return new ReconciledElement(
                            source: $element,
                            disposition: 'captured',
                            reason: 'contact string found in a content block URL',
                            evidence: $blockUrl,
                        );
                    }
                }
                if ($this->textMatches($this->normalizeText($rawLower), $index['text'])) {
                    return new ReconciledElement(
                        source: $element,
                        disposition: 'captured',
                        reason: 'contact string found in a content block text',
                        evidence: $rawLower,
                    );
                }
            }
        }
        // Fallback for kinds without a specific matcher (embed) —
        // best-effort text match against the normalized page text.
        if (! $isTextKind && ! $isUrlKind && ! $isContact) {
            $needle = $this->normalizeText($element->content);
            if ($needle !== '' && $this->textMatches($needle, $index['text'])) {
                return new ReconciledElement(
                    source: $element,
                    disposition: 'captured',
                    reason: 'content found in a content block',
                    evidence: mb_substr($needle, 0, 80),
                );
            }
        }

        // SUPERSEDED via platform block on this page.
        if ($platformBlockType !== null) {
            return new ReconciledElement(
                source: $element,
                disposition: 'superseded',
                reason: "regenerated by live {$platformBlockType} block on this page",
                evidence: "platform_block:{$platformBlockType}",
            );
        }

        // SUPERSEDED via scrub sidecar — the containing block was
        // dropped (stale live-widget capture, SE-promo removal). We
        // match either the element's text against the scrub summary,
        // or the element's URL against the scrub summary's captured
        // hrefs. Scrub summaries are already normalized human strings,
        // so a normalized-substring check is enough.
        $needleForScrub = $isUrlKind || $isContact
            ? $this->normalizeUrl($element->content)
            : $this->normalizeText($element->content);
        if ($needleForScrub !== '') {
            $summariesLower = mb_strtolower($scrubIndex['text']);
            if (str_contains($summariesLower, mb_strtolower($needleForScrub))) {
                $kind = $scrubIndex['kinds'] === [] ? 'scrub' : $scrubIndex['kinds'][0];

                return new ReconciledElement(
                    source: $element,
                    disposition: 'superseded',
                    reason: "removed by post-assembly scrubber ({$kind})",
                    evidence: 'scrub_summary_match',
                );
            }
        }

        // EXCLUDED — intentional discard by a documented ExclusionRules
        // pattern (source-platform chrome, widget artefacts, boilerplate).
        $exclusion = $this->exclusions->classify($element, $sourceOrigin);
        if ($exclusion['excluded']) {
            return new ReconciledElement(
                source: $element,
                disposition: 'excluded',
                reason: $exclusion['reason'],
                evidence: 'rule:'.$exclusion['rule'],
            );
        }

        // OUT_OF_SCOPE — content type deliberately not migrated in this
        // version (news, board, contacts, sponsors). Named by the
        // eventual platform feature that will own the content. Runs
        // AFTER exclusion — a widget artefact is chrome, not scoped
        // content.
        $scope = $this->outOfScope->classify($element, $puckPayload, $pageTitle);
        if ($scope['out_of_scope']) {
            return new ReconciledElement(
                source: $element,
                disposition: 'out_of_scope',
                reason: $scope['reason'],
                evidence: "category:{$scope['category']}|feature:{$scope['feature']}",
            );
        }

        // DROPPED — the failure channel.
        return new ReconciledElement(
            source: $element,
            disposition: 'dropped',
            reason: 'present in source, absent from rebuilt page, no platform / scrub supersession accounts for it',
            evidence: '',
        );
    }

    /**
     * @param  array<string, mixed>  $puckPayload
     * @return array{text: string, urls: array<int, string>}
     */
    private function buildBlockIndex(array $puckPayload): array
    {
        $texts = [];
        /** @var array<int, string> $urls */
        $urls = [];
        $content = is_array($puckPayload['content'] ?? null) ? $puckPayload['content'] : [];
        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }
            $this->collectFromBlock($block, $texts, $urls);
        }

        return [
            'text' => $this->normalizeText(implode("\n", $texts)),
            'urls' => array_values(array_unique($urls)),
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<int, string>  $texts
     * @param  array<int, string>  $urls
     */
    private function collectFromBlock(array $block, array &$texts, array &$urls): void
    {
        $props = is_array($block['props'] ?? null) ? $block['props'] : [];
        foreach ($props as $key => $value) {
            $this->collectFromValue((string) $key, $value, $texts, $urls);
        }
    }

    /**
     * @param  array<int, string>  $texts
     * @param  array<int, string>  $urls
     */
    private function collectFromValue(string $key, mixed $value, array &$texts, array &$urls): void
    {
        if (is_string($value)) {
            if ($value === '') {
                return;
            }
            // Heuristic: is this key a URL-carrying field?
            $keyLower = mb_strtolower($key);
            $isUrlKey = in_array($keyLower, [
                'href', 'src', 'background_image', 'image', 'url', 'link', 'video_url', 'thumbnail',
            ], true);
            if ($isUrlKey || $this->looksLikeUrl($value)) {
                $urls[] = $value;
            } else {
                $texts[] = $value;
            }

            return;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $this->collectFromValue(is_string($k) ? $k : $key, $v, $texts, $urls);
            }
        }
    }

    /**
     * @param  array<int, ScrubIssue|array<string, mixed>>  $scrubs
     * @return array{text: string, urls: array<int, string>, kinds: array<int, string>}
     */
    private function buildScrubIndex(array $scrubs): array
    {
        $textParts = [];
        /** @var array<int, string> $urls */
        $urls = [];
        /** @var array<int, string> $kinds */
        $kinds = [];
        foreach ($scrubs as $s) {
            if ($s instanceof ScrubIssue) {
                $textParts[] = $s->dropped_content_summary;
                $kinds[] = $s->kind->value;
            } elseif (is_array($s)) {
                if (is_string($s['dropped_content_summary'] ?? null)) {
                    $textParts[] = $s['dropped_content_summary'];
                }
                if (is_string($s['kind'] ?? null)) {
                    $kinds[] = $s['kind'];
                }
            }
        }
        $text = implode("\n", $textParts);
        // Also extract URLs from within the summary (they're plain).
        if (preg_match_all('/https?:\/\/[^\s\|"\'<>]+/', $text, $m)) {
            foreach ($m[0] as $u) {
                $urls[] = $u;
            }
        }

        return [
            'text' => $text,
            'urls' => $urls,
            'kinds' => $kinds,
        ];
    }

    /**
     * @param  array<string, mixed>  $puckPayload
     */
    private function platformBlockTypeOnPage(array $puckPayload): ?string
    {
        $content = is_array($puckPayload['content'] ?? null) ? $puckPayload['content'] : [];
        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }
            $t = $block['type'] ?? null;
            if (is_string($t) && str_starts_with($t, 'Platform')) {
                return $t;
            }
        }

        return null;
    }

    // Substring match, tolerant to a source element being trimmed of
    // trailing punctuation on either side. The reconciler ERRS on the
    // side of matching when there's meaningful overlap — the failure
    // channel's job is to catch content the rebuild DIDN'T touch, not
    // to police the last character of every sentence.
    private function textMatches(string $needle, string $haystack): bool
    {
        if ($needle === '' || $haystack === '') {
            return false;
        }
        $needleTrim = trim($needle, " \t\n\r\0\x0B.,;:!?()[]\"'");
        if ($needleTrim === '') {
            return false;
        }
        if (str_contains($haystack, $needleTrim)) {
            return true;
        }
        // Reverse: if the block text (or a phrase from it) is a
        // substring of a longer source sentence, count that as a match
        // — the source line "About Us Overview" containing block
        // "About Us" is still preserved content.
        // Guard against trivially short block phrases matching noise:
        // require at least 12 chars of overlap.
        if (mb_strlen($needleTrim) >= 12) {
            // Try progressively shorter prefixes of the source needle
            // against the haystack. This handles the "block prop is a
            // truncated form of the source paragraph" case.
            $minLen = max(12, (int) (mb_strlen($needleTrim) * 0.6));
            for ($len = mb_strlen($needleTrim); $len >= $minLen; $len -= 4) {
                $prefix = mb_substr($needleTrim, 0, $len);
                if (str_contains($haystack, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeText(string $s): string
    {
        // Strip Firecrawl backslash escapes.
        $s = str_replace(['\\*', '\\_', '\\-', '\\.', '\\('], ['*', '_', '-', '.', '('], $s);
        // Strip markdown emphasis + heading markers + link decoration.
        $s = preg_replace('/\*+|_+/', '', $s) ?? $s;
        $s = preg_replace('/^#{1,6}\s+/m', '', $s) ?? $s;
        // Turn `[label](url)` into just `label` for text matching.
        $s = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $s) ?? $s;
        // Turn Unicode quotes/dashes into ASCII.
        $s = strtr($s, [
            "\u{2018}" => "'",
            "\u{2019}" => "'",
            "\u{201C}" => '"',
            "\u{201D}" => '"',
            "\u{2013}" => '-',
            "\u{2014}" => '-',
        ]);
        // Collapse whitespace.
        $s = preg_replace('/\s+/', ' ', trim($s)) ?? $s;

        return mb_strtolower($s);
    }

    private function normalizeUrl(string $u): string
    {
        $u = trim($u);
        if ($u === '') {
            return '';
        }
        // Strip trailing punctuation attached by markdown parsing.
        $u = rtrim($u, ')\'".,;:');
        // Strip query string and fragment (may vary between capture and rebuild).
        $q = parse_url($u);
        if (! is_array($q)) {
            return mb_strtolower($u);
        }
        $scheme = isset($q['scheme']) && is_string($q['scheme']) ? mb_strtolower($q['scheme']) : '';
        $host = isset($q['host']) && is_string($q['host']) ? mb_strtolower($q['host']) : '';
        $path = isset($q['path']) && is_string($q['path']) ? $q['path'] : '';
        // Non-http (mailto:, tel:, #anchor) — return the whole thing lowercased.
        if ($scheme === '' || $host === '') {
            return mb_strtolower($u);
        }

        return $scheme.'://'.$host.$path;
    }

    /**
     * @param  array<int, string>  $blockUrls
     */
    private function matchUrl(string $needle, array $blockUrls): bool
    {
        foreach ($blockUrls as $u) {
            $n = $this->normalizeUrl($u);
            if ($n === '') {
                continue;
            }
            if ($n === $needle || str_ends_with($n, $needle) || str_ends_with($needle, $n)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeUrl(string $s): bool
    {
        return str_starts_with($s, 'http://')
            || str_starts_with($s, 'https://')
            || str_starts_with($s, 'mailto:')
            || str_starts_with($s, 'tel:')
            || str_starts_with($s, '//');
    }
}
