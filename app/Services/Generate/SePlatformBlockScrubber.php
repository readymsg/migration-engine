<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\AssemblyFailure;
use App\Data\AssemblyResult;
use App\Data\AssemblyStatus;
use App\Data\PuckOutput;
use App\Data\ScrubIssue;
use App\Data\ScrubKind;
use Spatie\LaravelData\DataCollection;

// Post-assembly deterministic scrubber for SE-injected content. Consumes
// an AssemblyResult, produces a NEW AssemblyResult where offending
// top-level blocks have been dropped and scrub_issues_by_slug records
// each drop with a visible reason + dropped_content_summary.
//
// SCOPE — three PRECISION-FIRST detection layers, in order:
//
//   Layer 1 (SE-promo href scan):
//     Every string prop with '://' that matches one of the
//     SE_PROMO_HREF_PATTERNS regexes is a SE-promo link. Narrower than
//     SePlatformContentDetector::SE_PLATFORM_PATTERNS (which is
//     calibrated for PAGE-level "overwhelmingly SE-tutorial" judgment).
//     Deliberately excludes help.sportsengine.com / my.sportngin.com /
//     mobile-help — those hosts ARE org-linkable (a coaches page might
//     legitimately link to a help article). App-store links and SE
//     solutions marketing are the unambiguous set: nobody but SE's own
//     template links to itunes.apple.com/app/sport-ngin/ or
//     play.google.com/details?id=com.sportngin.
//
//     Action per block type:
//       - ButtonGroup.buttons[i].href SE-promo → drop that button. If
//         all buttons drop → drop the ButtonGroup.
//       - Card.href SE-promo → drop the whole Card (the link IS its
//         CTA).
//       - Hero.cta.href SE-promo → clear the cta prop (Hero body
//         survives — the promo CTA doesn't).
//
//   Layer 2 (exact label whitelist):
//     A closed set of EXACT full-string matches for known SE-promo
//     phrases SE templates inject. EXACT means eq(), not str_contains
//     — a label that merely mentions SportsEngine ("we've been on
//     SportsEngine since 2015") is LEFT ALONE. The href layer catches
//     the promo variants that matter; label scrubbing is only for the
//     no-href label-only buttons ("Stay Connected to Your Team with
//     SportsEngine" with href="#"). False-scrubbing real org content is
//     silent-loss pointed at the wrong target — worse than missing a
//     promo variant. Err TIGHT.
//
//     Action: same as Layer 1 (drop the button/card/hero.cta with the
//     matching label).
//
//   Layer 3 (stale-countdown pattern):
//     A Card whose props.body matches STALE_COUNTDOWN_PATTERN is SE's
//     live JS widget scraped as static text (JS didn't run during
//     Firecrawl fetch). Multi-unit format ("N Days N Hours N Minutes")
//     is the signal — precise enough to not false-positive on natural
//     org copy ("See you in 3 days", "Registration closes Monday" don't
//     have all three units in sequence).
//
//     Action: drop the Card. Applies to top-level Cards AND to Cards
//     nested inside Columns.columns[i].children[j].
//
// Every scrub emits a ScrubIssue with page_slug, block_index (in the
// ORIGINAL content array), component_type, kind, reason,
// dropped_content_summary. Silent scrubbing is FORBIDDEN — audit trail
// beats stealth. SCORE & LOG surfaces these; a reviewer can undo a
// false positive.
//
// FAITHFUL-REBUILD tension resolution: this is the third leg of the
// SE-content-omission tripod:
//   1. SE platform LINKS in nav → parked (RootNavPlanner::isSePlatformLink).
//   2. SE platform CONTENT PAGES → parked (SePlatformContentDetector).
//   3. SE platform CONTENT BLOCKS → scrubbed here.
// All three surface the omission visibly in the ledger. Precedent
// consistent.
final class SePlatformBlockScrubber
{
    /**
     * Layer 1 pattern set. Narrower than
     * SePlatformContentDetector::SE_PLATFORM_PATTERNS on purpose — see
     * class docblock. Every entry is a regex anchored at scheme.
     *
     * @var array<int, string>
     */
    public const SE_PROMO_HREF_PATTERNS = [
        '#^https?://itunes\.apple\.com/[^/]+/app/sport-ngin/#i',
        '#^https?://play\.google\.com/store/apps/details\?id=com\.sportngin\.#i',
        '#^https?://(www\.)?sportsengine\.com/solutions/#i',
    ];

    /**
     * Layer 2 EXACT-match phrases. Full-string, case-insensitive, closed.
     * Every entry is a phrase SE's template injects verbatim; adding
     * one here is a decision to remove exactly that copy across every
     * site the scrubber runs on. Do NOT add substrings; do NOT add
     * fuzzy variants.
     *
     * @var array<int, string>
     */
    public const SE_PROMO_LABEL_WHITELIST = [
        'stay connected to your team with sportsengine',
        'get the sportsengine app',
        'sportsengine for apple users',
        'sportsengine for android users',
        'download the sportsengine app',
        'sportsengine mobile app',
    ];

    // Multi-unit countdown pattern. Must see all three of Days/Hours/
    // Minutes in sequence with numeric prefixes — precise enough to not
    // false-positive on natural copy. Case-insensitive.
    //
    // Each number tolerates OPTIONAL markdown emphasis wrappers (`*` or
    // `**`) around it. Firecrawl converts SE's live-countdown widget
    // `<strong>N</strong>` to `**N**` in the captured body, and the
    // block-fill agent (per its faithfulness rule) copies that
    // verbatim into Card.body. Without the `\*{0,2}` tolerance the
    // regex misses the decorated form and the countdown Card survives
    // to render as `**55** Days ...` literally. See
    // decorated_countdown_with_markdown_bold_wrappers_is_scrubbed test.
    private const STALE_COUNTDOWN_PATTERN = '/\*{0,2}\d+\*{0,2}\s+Days?\s+\*{0,2}\d+\*{0,2}\s+Hours?\s+\*{0,2}\d+\*{0,2}\s+Minutes?/i';

    public function run(AssemblyResult $assembly): AssemblyResult
    {
        /** @var array<int, PuckOutput> $originalPages */
        $originalPages = $assembly->pages->items();
        /** @var array<int, PuckOutput> $scrubbedPages */
        $scrubbedPages = [];
        /** @var array<string, array<int, ScrubIssue>> $scrubIssues */
        $scrubIssues = [];

        foreach ($originalPages as $puck) {
            [$newContent, $pageIssues] = $this->scrubPage($puck);

            $scrubbedPages[] = new PuckOutput(
                page_slug: $puck->page_slug,
                content: $newContent,
                root: $puck->root,
                zones: $puck->zones,
            );

            if ($pageIssues !== []) {
                $scrubIssues[$puck->page_slug] = $pageIssues;
            }
        }

        return new AssemblyResult(
            pages: new DataCollection(PuckOutput::class, $scrubbedPages),
            failures: new DataCollection(AssemblyFailure::class, $assembly->failures->items()),
            block_issues_by_slug: $assembly->block_issues_by_slug,
            status: $this->resolveStatus($assembly, $scrubIssues),
            style_brief: $assembly->style_brief,
            scrub_issues_by_slug: $scrubIssues,
        );
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, ScrubIssue>}
     */
    private function scrubPage(PuckOutput $puck): array
    {
        /** @var array<int, array<string, mixed>> $newContent */
        $newContent = [];
        /** @var array<int, ScrubIssue> $issues */
        $issues = [];

        foreach ($puck->content as $index => $block) {
            [$maybeNewBlock, $blockIssues] = $this->scrubBlock($block, $index);
            foreach ($blockIssues as $issue) {
                $issues[] = $issue;
            }
            if ($maybeNewBlock !== null) {
                $newContent[] = $maybeNewBlock;
            }
        }

        return [$newContent, $issues];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array{0: null|array<string, mixed>, 1: array<int, ScrubIssue>}  first element null = drop the block; otherwise possibly-modified block. Issues always accompany drops.
     */
    private function scrubBlock(array $block, int $index): array
    {
        $type = is_string($block['type'] ?? null) ? $block['type'] : '';
        /** @var array<string, mixed> $props */
        $props = is_array($block['props'] ?? null) ? $block['props'] : [];

        return match ($type) {
            'ButtonGroup' => $this->scrubButtonGroup($block, $index, $props),
            'Card' => $this->scrubCardBlock($block, $index, $props),
            'Columns' => $this->scrubColumnsBlock($block, $index, $props),
            'Hero' => $this->scrubHeroBlock($block, $index, $props),
            default => [$block, []],
        };
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $props
     * @return array{0: null|array<string, mixed>, 1: array<int, ScrubIssue>}  first element null = drop the block; otherwise possibly-modified block. Issues always accompany drops.
     */
    private function scrubButtonGroup(array $block, int $index, array $props): array
    {
        /** @var array<int, array<string, mixed>> $buttons */
        $buttons = is_array($props['buttons'] ?? null) ? $props['buttons'] : [];

        /** @var array<int, array<string, mixed>> $keptButtons */
        $keptButtons = [];
        $droppedByHref = 0;
        $droppedByLabel = 0;
        foreach ($buttons as $button) {
            if (! is_array($button)) {
                continue;
            }
            $href = is_string($button['href'] ?? null) ? $button['href'] : '';
            $label = is_string($button['label'] ?? null) ? $button['label'] : '';

            if ($href !== '' && $this->isSePromoHref($href)) {
                $droppedByHref++;

                continue;
            }
            if ($label !== '' && $this->isSePromoLabel($label)) {
                $droppedByLabel++;

                continue;
            }
            $keptButtons[] = $button;
        }

        // Nothing dropped → return unchanged, no issue.
        if ($droppedByHref === 0 && $droppedByLabel === 0) {
            return [$block, []];
        }

        // All buttons dropped → drop the whole ButtonGroup.
        if ($keptButtons === []) {
            // Kind classification: if ANY href drops happened, attribute
            // to Href (the stronger signal). Otherwise it was label-only.
            $kind = $droppedByHref > 0 ? ScrubKind::SePromoHref : ScrubKind::SePromoLabel;
            $reasonParts = [];
            if ($droppedByHref > 0) {
                $reasonParts[] = "{$droppedByHref} button(s) with SE-promo hrefs";
            }
            if ($droppedByLabel > 0) {
                $reasonParts[] = "{$droppedByLabel} button(s) with whitelist promo labels";
            }
            $reason = 'SE-promo ButtonGroup — '.implode(' + ', $reasonParts);

            return [
                // sentinel: null block, drop
                null,
                [new ScrubIssue(
                    block_index: $index,
                    component_type: 'ButtonGroup',
                    kind: $kind,
                    reason: $reason,
                    dropped_content_summary: sprintf(
                        '%d buttons: %s',
                        count($buttons),
                        implode(' | ', array_map(
                            static fn (array $b): string => sprintf(
                                '"%s" -> %s',
                                is_string($b['label'] ?? null) ? substr($b['label'], 0, 60) : '',
                                is_string($b['href'] ?? null) ? substr($b['href'], 0, 60) : '',
                            ),
                            $buttons,
                        )),
                    ),
                )],
            ];
        }

        // Some buttons kept — rebuild with survivors.
        $newProps = $props;
        $newProps['buttons'] = $keptButtons;
        $newBlock = $block;
        $newBlock['props'] = $newProps;

        $reasonParts = [];
        if ($droppedByHref > 0) {
            $reasonParts[] = "{$droppedByHref} SE-promo href(s)";
        }
        if ($droppedByLabel > 0) {
            $reasonParts[] = "{$droppedByLabel} whitelist label(s)";
        }

        return [
            $newBlock,
            [new ScrubIssue(
                block_index: $index,
                component_type: 'ButtonGroup',
                kind: $droppedByHref > 0 ? ScrubKind::SePromoHref : ScrubKind::SePromoLabel,
                reason: 'partial ButtonGroup scrub — '.implode(' + ', $reasonParts).' removed, others kept',
                dropped_content_summary: sprintf(
                    'dropped %d of %d buttons',
                    ($droppedByHref + $droppedByLabel),
                    count($buttons),
                ),
            )],
        ];
    }

    // Sentinel handling: `scrubButtonGroup`'s empty-buttons branch
    // returns [null, [issue]] — the outer scrubPage detects null and
    // drops the block. We can't return null-directly-from-null-tuple in
    // strict types, so we bake it into the return shape.

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $props
     * @return array{0: null|array<string, mixed>, 1: array<int, ScrubIssue>}  first element null = drop the block; otherwise possibly-modified block. Issues always accompany drops.
     */
    private function scrubCardBlock(array $block, int $index, array $props): array
    {
        // Layer 3: stale countdown body.
        $body = is_string($props['body'] ?? null) ? $props['body'] : '';
        if ($body !== '' && preg_match(self::STALE_COUNTDOWN_PATTERN, $body) === 1) {
            $title = is_string($props['title'] ?? null) ? $props['title'] : '';

            return [
                null,
                [new ScrubIssue(
                    block_index: $index,
                    component_type: 'Card',
                    kind: ScrubKind::StaleCountdown,
                    reason: 'stale countdown from SE live-widget scraped as static text',
                    dropped_content_summary: sprintf(
                        'title="%s" body="%s"',
                        substr($title, 0, 60),
                        substr($body, 0, 80),
                    ),
                )],
            ];
        }

        // Layer 1: Card.href SE-promo → drop.
        $href = is_string($props['href'] ?? null) ? $props['href'] : '';
        if ($href !== '' && $this->isSePromoHref($href)) {
            $title = is_string($props['title'] ?? null) ? $props['title'] : '';

            return [
                null,
                [new ScrubIssue(
                    block_index: $index,
                    component_type: 'Card',
                    kind: ScrubKind::SePromoHref,
                    reason: 'Card with SE-promo href — the link IS the CTA, whole card scrubbed',
                    dropped_content_summary: sprintf(
                        'title="%s" href=%s',
                        substr($title, 0, 60),
                        substr($href, 0, 80),
                    ),
                )],
            ];
        }

        // Layer 2: Card.title matching SE-promo whitelist → drop.
        $title = is_string($props['title'] ?? null) ? $props['title'] : '';
        if ($title !== '' && $this->isSePromoLabel($title)) {
            return [
                null,
                [new ScrubIssue(
                    block_index: $index,
                    component_type: 'Card',
                    kind: ScrubKind::SePromoLabel,
                    reason: 'Card title matches SE-promo whitelist',
                    dropped_content_summary: sprintf('title="%s"', substr($title, 0, 80)),
                )],
            ];
        }

        return [$block, []];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $props
     * @return array{0: null|array<string, mixed>, 1: array<int, ScrubIssue>}  first element null = drop the block; otherwise possibly-modified block. Issues always accompany drops.
     */
    private function scrubColumnsBlock(array $block, int $index, array $props): array
    {
        /** @var array<int, array<string, mixed>> $columns */
        $columns = is_array($props['columns'] ?? null) ? $props['columns'] : [];

        /** @var array<int, array<string, mixed>> $newColumns */
        $newColumns = [];
        $droppedChildCount = 0;
        $droppedSummaries = [];

        foreach ($columns as $col) {
            if (! is_array($col)) {
                continue;
            }
            /** @var array<int, array<string, mixed>> $children */
            $children = is_array($col['children'] ?? null) ? $col['children'] : [];
            /** @var array<int, array<string, mixed>> $keptChildren */
            $keptChildren = [];
            foreach ($children as $child) {
                if (! is_array($child)) {
                    continue;
                }
                $childType = is_string($child['type'] ?? null) ? $child['type'] : '';
                if ($childType !== 'Card') {
                    // Non-Card nested children pass through untouched.
                    // Nested Columns / other types are a future concern;
                    // the shapes we've seen in fixtures are Card only.
                    $keptChildren[] = $child;

                    continue;
                }
                /** @var array<string, mixed> $childProps */
                $childProps = is_array($child['props'] ?? null) ? $child['props'] : [];
                $childBody = is_string($childProps['body'] ?? null) ? $childProps['body'] : '';
                if ($childBody !== '' && preg_match(self::STALE_COUNTDOWN_PATTERN, $childBody) === 1) {
                    $childTitle = is_string($childProps['title'] ?? null) ? $childProps['title'] : '';
                    $droppedChildCount++;
                    $droppedSummaries[] = sprintf('title="%s"', substr($childTitle, 0, 40));

                    continue;
                }
                $keptChildren[] = $child;
            }

            // Column with survivors → keep, with updated children.
            if ($keptChildren !== []) {
                $newColumn = $col;
                $newColumn['children'] = $keptChildren;
                $newColumns[] = $newColumn;
            }
            // Column that emptied → drop the column (don't keep an empty
            // one — Puck would render an empty column slot).
        }

        // Nothing changed → return unchanged.
        if ($droppedChildCount === 0) {
            return [$block, []];
        }

        // All columns emptied → drop the whole Columns block.
        if ($newColumns === []) {
            return [
                null,
                [new ScrubIssue(
                    block_index: $index,
                    component_type: 'Columns',
                    kind: ScrubKind::StaleCountdown,
                    reason: 'Columns of stale countdowns — every nested Card matched the countdown pattern',
                    dropped_content_summary: sprintf(
                        '%d nested Cards with countdown text: %s',
                        $droppedChildCount,
                        implode(', ', $droppedSummaries),
                    ),
                )],
            ];
        }

        // Some columns survived — rebuild.
        $newProps = $props;
        $newProps['columns'] = $newColumns;
        $newBlock = $block;
        $newBlock['props'] = $newProps;

        return [
            $newBlock,
            [new ScrubIssue(
                block_index: $index,
                component_type: 'Columns',
                kind: ScrubKind::StaleCountdown,
                reason: 'partial Columns scrub — countdown Cards removed from some columns',
                dropped_content_summary: sprintf(
                    '%d nested countdown Cards dropped: %s',
                    $droppedChildCount,
                    implode(', ', $droppedSummaries),
                ),
            )],
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $props
     * @return array{0: null|array<string, mixed>, 1: array<int, ScrubIssue>}  first element null = drop the block; otherwise possibly-modified block. Issues always accompany drops.
     */
    private function scrubHeroBlock(array $block, int $index, array $props): array
    {
        /** @var array<string, mixed> $cta */
        $cta = is_array($props['cta'] ?? null) ? $props['cta'] : [];
        if ($cta === []) {
            return [$block, []];
        }

        $href = is_string($cta['href'] ?? null) ? $cta['href'] : '';
        $label = is_string($cta['label'] ?? null) ? $cta['label'] : '';

        $isPromoHref = $href !== '' && $this->isSePromoHref($href);
        $isPromoLabel = $label !== '' && $this->isSePromoLabel($label);

        if (! $isPromoHref && ! $isPromoLabel) {
            return [$block, []];
        }

        $newProps = $props;
        unset($newProps['cta']);
        $newBlock = $block;
        $newBlock['props'] = $newProps;

        $kind = $isPromoHref ? ScrubKind::SePromoHref : ScrubKind::SePromoLabel;
        $reason = $isPromoHref
            ? 'Hero.cta has SE-promo href — cta cleared, Hero body kept'
            : 'Hero.cta.label matches SE-promo whitelist — cta cleared, Hero body kept';

        return [
            $newBlock,
            [new ScrubIssue(
                block_index: $index,
                component_type: 'Hero',
                kind: $kind,
                reason: $reason,
                dropped_content_summary: sprintf('cta.label="%s" cta.href=%s', substr($label, 0, 60), substr($href, 0, 60)),
            )],
        ];
    }

    private function isSePromoHref(string $url): bool
    {
        foreach (self::SE_PROMO_HREF_PATTERNS as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isSePromoLabel(string $label): bool
    {
        $needle = strtolower(trim($label));

        return in_array($needle, self::SE_PROMO_LABEL_WHITELIST, true);
    }

    /**
     * Scrubbing doesn't change page-level failures or the diff
     * universe, but a successful scrub means the AssemblyResult now
     * carries scrub sidecar entries. Downstream (SCORE & LOG) treats
     * scrubs as informational — they don't degrade status by themselves
     * (unlike block_issues, which indicate assembler-side coercion).
     * A pure Complete assembly with scrubs stays Complete.
     *
     * @param  array<string, array<int, ScrubIssue>>  $scrubIssues
     */
    private function resolveStatus(AssemblyResult $original, array $scrubIssues): AssemblyStatus
    {
        return $original->status;
    }
}
