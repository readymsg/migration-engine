<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\AssignedBlock;
use App\Data\AssignmentDisposition;
use App\Data\ScrubIssue;
use App\Data\ScrubKind;
use App\Data\TeamlinktBlockBucket;
use App\Data\TeamlinktBlockType;

// Deterministic post-assembly assigner: given the assembled Puck page_map
// (content pages from Assembler + platform pages from PlatformBlockRenderer)
// plus the scrub sidecar, tag each block with a TeamlinktBlockType.
//
// Type-only. No prop mapping (we lack real per-block schemas for TeamLinkt
// — see confirmed builder-bundle notes on the ChatContext). Answers ONE
// question per block: "which TeamLinkt block would this land as, and did
// we CAPTURE the source, SUPERSEDE it with a live block, or fail to map?"
//
// Three input streams:
//   1. Content Puck blocks (Hero, Text, Card, Columns, ...) → Content
//      bucket TeamlinktBlockType, disposition = Captured (or Unmapped if
//      no confident mapping exists).
//   2. Platform Puck blocks (PlatformSchedule, PlatformCalendar, ...) →
//      Platform bucket TeamlinktBlockType, disposition = Superseded.
//      Scraped equivalent DISCARDED — the coverage report notes the
//      supersession.
//   3. Scrubbed elements (from scrub_issues_by_slug) → Platform-adjacent
//      or informational supersession. StaleCountdown becomes an
//      EventMarquee superseded note (a live event marquee replaces the
//      static countdown). SE-promo scrubs are informational-only — they
//      DO NOT create an AssignedBlock (nothing to place; the SE promo is
//      not org content that needs replacing).
final class BlockTypeAssigner
{
    /**
     * Puck block `type` string → the matching TeamlinktBlockType.
     * Only exact 1:1 matches live here; conditional mappings (Columns
     * needing to inspect nesting, Card standalone vs card grid) run in
     * assignContentBlock() below.
     *
     * @var array<string, TeamlinktBlockType>
     */
    private const DIRECT_CONTENT_MAP = [
        'Hero' => TeamlinktBlockType::Hero,
        'Text' => TeamlinktBlockType::Text,
        'Image' => TeamlinktBlockType::Image,
    ];

    /**
     * Platform Puck `type` string → TeamlinktBlockType. Every value in
     * PlatformBlockRenderer::TYPE_TO_PUCK must appear here — the assigner
     * MUST have a route for every platform-block type the renderer emits.
     *
     * @var array<string, TeamlinktBlockType>
     */
    private const PLATFORM_MAP = [
        'PlatformSchedule' => TeamlinktBlockType::Schedule,
        'PlatformScores' => TeamlinktBlockType::Scores,
        'PlatformStandings' => TeamlinktBlockType::Standings,
        'PlatformRoster' => TeamlinktBlockType::TeamRoster,
        'PlatformTeams' => TeamlinktBlockType::Teams,
        'PlatformDivisions' => TeamlinktBlockType::SubOrganizations,
        'PlatformContacts' => TeamlinktBlockType::Executives,
        'PlatformCalendar' => TeamlinktBlockType::EventMarquee,
        'PlatformNews' => TeamlinktBlockType::NewsList,
        'PlatformTeam' => TeamlinktBlockType::TeamCard,
    ];

    /**
     * ConversionResult.scrub_issues_by_slug is typed `array` (not a
     * DataCollection) so Spatie hydrates it as `array<string, array<int,
     * array>>` — plain arrays, not ScrubIssue objects. Accept either
     * shape here so callers can pass the raw sidecar without
     * re-hydrating.
     *
     * @param  array<string, array<string, mixed>>  $pageMap  page_slug → assembled Puck payload (as ConversionResult.page_map)
     * @param  array<string, string>  $pageTitles  page_slug → human title (usually Puck root.title)
     * @param  array<string, array<int, ScrubIssue|array<string, mixed>>>  $scrubIssuesBySlug  ConversionResult.scrub_issues_by_slug passthrough
     * @return array<int, AssignedBlock> flat list, ordered by (page_slug, block_index)
     */
    public function assign(array $pageMap, array $pageTitles = [], array $scrubIssuesBySlug = []): array
    {
        /** @var array<int, AssignedBlock> $out */
        $out = [];

        foreach ($pageMap as $pageSlug => $payload) {
            $title = $pageTitles[$pageSlug] ?? $this->titleFromPayload($payload, $pageSlug);
            $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];

            foreach ($content as $index => $block) {
                if (! is_array($block)) {
                    continue;
                }
                $out[] = $this->assignBlock($pageSlug, $title, (int) $index, $block);
            }

            // Scrubbed elements → SUPERSEDED entries appended after the
            // real blocks so the coverage report still reflects them per
            // page. The scrubber removed them post-assembly, so they
            // don't appear in page_map but they DID exist in the source.
            $scrubs = $scrubIssuesBySlug[$pageSlug] ?? [];
            foreach ($scrubs as $scrub) {
                $scrubIssue = $scrub instanceof ScrubIssue ? $scrub : $this->hydrateScrubIssue($scrub);
                if ($scrubIssue === null) {
                    continue;
                }
                $out[] = $this->assignScrub($pageSlug, $title, $scrubIssue);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|mixed  $raw
     */
    private function hydrateScrubIssue(mixed $raw): ?ScrubIssue
    {
        if (! is_array($raw)) {
            return null;
        }
        $kindValue = is_string($raw['kind'] ?? null) ? $raw['kind'] : null;
        $kind = $kindValue !== null ? ScrubKind::tryFrom($kindValue) : null;
        if ($kind === null) {
            return null;
        }

        return new ScrubIssue(
            block_index: is_int($raw['block_index'] ?? null) ? $raw['block_index'] : 0,
            component_type: is_string($raw['component_type'] ?? null) ? $raw['component_type'] : '',
            kind: $kind,
            reason: is_string($raw['reason'] ?? null) ? $raw['reason'] : '',
            dropped_content_summary: is_string($raw['dropped_content_summary'] ?? null) ? $raw['dropped_content_summary'] : '',
        );
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function assignBlock(string $pageSlug, string $pageTitle, int $index, array $block): AssignedBlock
    {
        $type = is_string($block['type'] ?? null) ? $block['type'] : '';
        $props = is_array($block['props'] ?? null) ? $block['props'] : [];

        // Platform block? → Superseded.
        if (isset(self::PLATFORM_MAP[$type])) {
            $target = self::PLATFORM_MAP[$type];

            return new AssignedBlock(
                page_slug: $pageSlug,
                page_title: $pageTitle,
                block_index: $index,
                source_kind: $this->sourceKindForPlatform($type),
                teamlinkt_type: $target,
                bucket: TeamlinktBlockBucket::Platform,
                disposition: AssignmentDisposition::Superseded,
                reason: 'live '.strtolower($target->value).' block replaces scraped SE-'.$this->sourceKindForPlatform($type).' page',
                source_snippet: null,
            );
        }

        return $this->assignContentBlock($pageSlug, $pageTitle, $index, $type, $props);
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function assignContentBlock(
        string $pageSlug,
        string $pageTitle,
        int $index,
        string $type,
        array $props,
    ): AssignedBlock {
        // Straight 1:1 mappings.
        if (isset(self::DIRECT_CONTENT_MAP[$type])) {
            $target = self::DIRECT_CONTENT_MAP[$type];

            return $this->captured(
                pageSlug: $pageSlug,
                pageTitle: $pageTitle,
                index: $index,
                sourceKind: strtolower($type),
                target: $target,
                reason: "content-carrying {$type} lands as {$target->value}",
                snippet: $this->snippetFromProps($props),
            );
        }

        // Heading has no dedicated TeamLinkt block — carried as Text with
        // heading-styling; still captured (the text data survives).
        if ($type === 'Heading') {
            return $this->captured(
                pageSlug: $pageSlug,
                pageTitle: $pageTitle,
                index: $index,
                sourceKind: 'heading',
                target: TeamlinktBlockType::Text,
                reason: 'no dedicated TeamLinkt Heading block; heading text carried as Text',
                snippet: is_string($props['text'] ?? null) ? $props['text'] : null,
            );
        }

        // Card standalone → Section (title + body — same shape as
        // TeamLinkt's Section). Card inside Columns is handled below.
        if ($type === 'Card') {
            return $this->captured(
                pageSlug: $pageSlug,
                pageTitle: $pageTitle,
                index: $index,
                sourceKind: 'card',
                target: TeamlinktBlockType::Section,
                reason: 'standalone Card (title + body) lands as Section',
                snippet: is_string($props['title'] ?? null) ? $props['title'] : null,
            );
        }

        // ButtonGroup → Button (TeamLinkt has no ButtonGroup; a group of
        // buttons on one page lands as N Button blocks, but as a TYPE
        // assignment we mark it Button. The prop-mapping fan-out is a
        // later concern.)
        if ($type === 'ButtonGroup') {
            $buttons = is_array($props['buttons'] ?? null) ? $props['buttons'] : [];
            $labels = [];
            foreach ($buttons as $b) {
                if (is_array($b) && is_string($b['label'] ?? null)) {
                    $labels[] = $b['label'];
                }
            }

            return $this->captured(
                pageSlug: $pageSlug,
                pageTitle: $pageTitle,
                index: $index,
                sourceKind: 'button_group',
                target: TeamlinktBlockType::Button,
                reason: 'ButtonGroup lands as Button block(s)',
                snippet: $labels === [] ? null : implode(' / ', $labels),
            );
        }

        // Columns: inspect shape.
        //   - Every child is Card AND ≥3 columns → FeatureGrid.
        //   - Every child is Card AND 2 columns → FeatureGrid (still
        //     card-shaped; TwoColumn is prose layout).
        //   - Every child is Card AND 1 column → Section (single card).
        //   - Exactly 2 columns of mixed prose → TwoColumn.
        //   - 3+ columns of mixed content → Grid.
        //   - Otherwise → Section (safe non-lossy fallback).
        if ($type === 'Columns') {
            $columns = is_array($props['columns'] ?? null) ? $props['columns'] : [];
            $columnCount = count($columns);
            $childCardCount = 0;
            $childCount = 0;
            $cardTitles = [];
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
                    if (($child['type'] ?? null) === 'Card') {
                        $childCardCount++;
                        $t = $child['props']['title'] ?? null;
                        if (is_string($t) && $t !== '') {
                            $cardTitles[] = $t;
                        }
                    }
                }
            }
            $allCards = $childCount > 0 && $childCardCount === $childCount;

            [$target, $why] = match (true) {
                $allCards && $columnCount >= 2 => [TeamlinktBlockType::FeatureGrid, 'Columns of Cards lands as FeatureGrid'],
                $allCards && $columnCount === 1 => [TeamlinktBlockType::Section, 'single-column Card lands as Section'],
                $columnCount === 2 => [TeamlinktBlockType::TwoColumn, 'two-column prose layout lands as TwoColumn'],
                $columnCount >= 3 => [TeamlinktBlockType::Grid, 'multi-column layout lands as Grid'],
                default => [TeamlinktBlockType::Section, 'Columns without recognisable shape lands as Section (safe non-lossy fallback)'],
            };

            $snippet = $cardTitles === [] ? null : implode(' / ', array_slice($cardTitles, 0, 6));

            return $this->captured(
                pageSlug: $pageSlug,
                pageTitle: $pageTitle,
                index: $index,
                sourceKind: 'columns',
                target: $target,
                reason: $why,
                snippet: $snippet,
            );
        }

        // Fell off the map — surface as Unmapped with Text fallback.
        return new AssignedBlock(
            page_slug: $pageSlug,
            page_title: $pageTitle,
            block_index: $index,
            source_kind: $type !== '' ? strtolower($type) : 'unknown',
            teamlinkt_type: TeamlinktBlockType::Text,
            bucket: TeamlinktBlockBucket::Content,
            disposition: AssignmentDisposition::Unmapped,
            reason: "no confident mapping for schema block '{$type}'; falling back to Text",
            source_snippet: $this->snippetFromProps($props),
        );
    }

    private function assignScrub(string $pageSlug, string $pageTitle, ScrubIssue $scrub): AssignedBlock
    {
        // Stale live-widget capture (countdown scraped as static text) →
        // Superseded by an EventMarquee. The scraped stale numbers are
        // discarded; the live block will render real upcoming events.
        if ($scrub->kind->value === 'stale_countdown') {
            return new AssignedBlock(
                page_slug: $pageSlug,
                page_title: $pageTitle,
                block_index: $scrub->block_index,
                source_kind: 'stale_countdown',
                teamlinkt_type: TeamlinktBlockType::EventMarquee,
                bucket: TeamlinktBlockBucket::Platform,
                disposition: AssignmentDisposition::Superseded,
                reason: 'stale countdown captured as static text replaced by live EventMarquee: '.$scrub->dropped_content_summary,
                source_snippet: $scrub->dropped_content_summary,
            );
        }

        // SE-promo scrubs (href or label) — the SE ad IS NOT org content,
        // so there is nothing to place in its stead. Emit an informational
        // Superseded entry with no TeamlinktBlockType so the coverage
        // report can still surface it under SUPERSEDED without pretending
        // we placed a new block. teamlinkt_type=null is the signal that
        // the source element was REMOVED, not REPLACED.
        return new AssignedBlock(
            page_slug: $pageSlug,
            page_title: $pageTitle,
            block_index: $scrub->block_index,
            source_kind: 'se_promo_'.($scrub->kind->value === 'se_promo_href' ? 'href' : 'label'),
            teamlinkt_type: null,
            bucket: TeamlinktBlockBucket::Platform,
            disposition: AssignmentDisposition::Superseded,
            reason: 'SE-promo content removed by scrubber (no TeamLinkt equivalent): '.$scrub->dropped_content_summary,
            source_snippet: $scrub->dropped_content_summary,
        );
    }

    private function captured(
        string $pageSlug,
        string $pageTitle,
        int $index,
        string $sourceKind,
        TeamlinktBlockType $target,
        string $reason,
        ?string $snippet,
    ): AssignedBlock {
        return new AssignedBlock(
            page_slug: $pageSlug,
            page_title: $pageTitle,
            block_index: $index,
            source_kind: $sourceKind,
            teamlinkt_type: $target,
            bucket: $target->bucket(),
            disposition: AssignmentDisposition::Captured,
            reason: $reason,
            source_snippet: $snippet,
        );
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function snippetFromProps(array $props): ?string
    {
        foreach (['heading', 'text', 'body', 'title', 'caption', 'alt', 'subheading'] as $key) {
            $v = $props[$key] ?? null;
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function titleFromPayload(array $payload, string $pageSlug): string
    {
        $root = is_array($payload['root'] ?? null) ? $payload['root'] : [];
        $t = $root['title'] ?? null;

        return is_string($t) && $t !== '' ? $t : $pageSlug;
    }

    private function sourceKindForPlatform(string $puckType): string
    {
        // Puck type "PlatformSchedule" → source_kind "schedule". Used
        // by the reason string ("...replaces scraped SE-schedule page")
        // and by the coverage report row.
        return strtolower(substr($puckType, strlen('Platform')));
    }
}
