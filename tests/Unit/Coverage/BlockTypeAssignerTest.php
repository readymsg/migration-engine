<?php

declare(strict_types=1);

namespace Tests\Unit\Coverage;

use App\Data\AssignmentDisposition;
use App\Data\ScrubIssue;
use App\Data\ScrubKind;
use App\Data\TeamlinktBlockBucket;
use App\Data\TeamlinktBlockType;
use App\Services\Generate\BlockTypeAssigner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BlockTypeAssignerTest extends TestCase
{
    private function assigner(): BlockTypeAssigner
    {
        return new BlockTypeAssigner;
    }

    #[Test]
    public function hero_text_image_content_blocks_are_captured_by_direct_mapping(): void
    {
        $out = $this->assigner()->assign([
            'p1' => [
                'root' => ['title' => 'P1'],
                'content' => [
                    ['type' => 'Hero', 'props' => ['heading' => 'Welcome']],
                    ['type' => 'Text', 'props' => ['body' => 'Some prose']],
                    ['type' => 'Image', 'props' => ['src' => 'https://x/y.jpg', 'alt' => 'x']],
                ],
            ],
        ]);

        $this->assertCount(3, $out);
        $this->assertSame(TeamlinktBlockType::Hero, $out[0]->teamlinkt_type);
        $this->assertSame(TeamlinktBlockType::Text, $out[1]->teamlinkt_type);
        $this->assertSame(TeamlinktBlockType::Image, $out[2]->teamlinkt_type);
        foreach ($out as $a) {
            $this->assertSame(AssignmentDisposition::Captured, $a->disposition);
            $this->assertSame(TeamlinktBlockBucket::Content, $a->bucket);
        }
    }

    #[Test]
    public function heading_falls_back_to_text_and_still_captures(): void
    {
        $out = $this->assigner()->assign([
            'p1' => [
                'root' => ['title' => 'P1'],
                'content' => [
                    ['type' => 'Heading', 'props' => ['text' => 'Season Recap', 'level' => 'h2']],
                ],
            ],
        ]);

        $this->assertCount(1, $out);
        $this->assertSame(TeamlinktBlockType::Text, $out[0]->teamlinkt_type);
        $this->assertSame(AssignmentDisposition::Captured, $out[0]->disposition);
        $this->assertSame('Season Recap', $out[0]->source_snippet);
    }

    #[Test]
    public function columns_of_cards_becomes_feature_grid(): void
    {
        $out = $this->assigner()->assign([
            'p1' => [
                'root' => ['title' => 'Board'],
                'content' => [
                    [
                        'type' => 'Columns',
                        'props' => [
                            'columns' => [
                                ['children' => [['type' => 'Card', 'props' => ['title' => 'Alice']]]],
                                ['children' => [['type' => 'Card', 'props' => ['title' => 'Bob']]]],
                                ['children' => [['type' => 'Card', 'props' => ['title' => 'Carol']]]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $out);
        $this->assertSame(TeamlinktBlockType::FeatureGrid, $out[0]->teamlinkt_type);
        $this->assertSame(AssignmentDisposition::Captured, $out[0]->disposition);
        $this->assertStringContainsString('Alice', $out[0]->source_snippet ?? '');
    }

    #[Test]
    public function two_column_prose_becomes_two_column(): void
    {
        $out = $this->assigner()->assign([
            'p1' => [
                'root' => ['title' => 'P1'],
                'content' => [
                    [
                        'type' => 'Columns',
                        'props' => [
                            'columns' => [
                                ['children' => [['type' => 'Text', 'props' => ['body' => 'left']]]],
                                ['children' => [['type' => 'Text', 'props' => ['body' => 'right']]]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(TeamlinktBlockType::TwoColumn, $out[0]->teamlinkt_type);
    }

    #[Test]
    public function platform_puck_block_is_superseded_and_never_captured(): void
    {
        $out = $this->assigner()->assign([
            'p1' => [
                'root' => ['title' => 'Schedule'],
                'content' => [
                    ['type' => 'PlatformSchedule', 'props' => ['org_id' => 'ngin-63620']],
                ],
            ],
        ]);

        $this->assertCount(1, $out);
        $this->assertSame(TeamlinktBlockType::Schedule, $out[0]->teamlinkt_type);
        $this->assertSame(TeamlinktBlockBucket::Platform, $out[0]->bucket);
        $this->assertSame(AssignmentDisposition::Superseded, $out[0]->disposition);
        // The distinction that matters for the report: superseded ≠ unmapped.
        $this->assertNotSame(AssignmentDisposition::Unmapped, $out[0]->disposition);
    }

    #[Test]
    public function unknown_puck_block_type_is_unmapped_with_text_fallback(): void
    {
        $out = $this->assigner()->assign([
            'p1' => [
                'root' => ['title' => 'P1'],
                'content' => [
                    ['type' => 'Timeline', 'props' => ['events' => []]],
                    ['type' => 'CountdownWidget', 'props' => []],
                ],
            ],
        ]);

        $this->assertCount(2, $out);
        foreach ($out as $a) {
            $this->assertSame(AssignmentDisposition::Unmapped, $a->disposition);
            $this->assertSame(TeamlinktBlockType::Text, $a->teamlinkt_type);
            $this->assertStringContainsString('no confident mapping', $a->reason);
        }
        $this->assertSame('timeline', $out[0]->source_kind);
        $this->assertSame('countdownwidget', $out[1]->source_kind);
    }

    #[Test]
    public function scrub_stale_countdown_becomes_superseded_event_marquee(): void
    {
        $out = $this->assigner()->assign(
            pageMap: [
                'p1' => ['root' => ['title' => 'Home'], 'content' => []],
            ],
            pageTitles: [],
            scrubIssuesBySlug: [
                'p1' => [
                    new ScrubIssue(
                        block_index: 1,
                        component_type: 'Columns',
                        kind: ScrubKind::StaleCountdown,
                        reason: 'stale live-widget capture',
                        dropped_content_summary: '3 nested Cards with countdown text',
                    ),
                ],
            ],
        );

        $this->assertCount(1, $out);
        $this->assertSame(AssignmentDisposition::Superseded, $out[0]->disposition);
        $this->assertSame(TeamlinktBlockType::EventMarquee, $out[0]->teamlinkt_type);
        $this->assertSame('stale_countdown', $out[0]->source_kind);
    }

    #[Test]
    public function scrub_se_promo_is_superseded_but_has_no_teamlinkt_target(): void
    {
        $out = $this->assigner()->assign(
            pageMap: [
                'p1' => ['root' => ['title' => 'Home'], 'content' => []],
            ],
            pageTitles: [],
            scrubIssuesBySlug: [
                'p1' => [
                    new ScrubIssue(
                        block_index: 5,
                        component_type: 'ButtonGroup',
                        kind: ScrubKind::SePromoHref,
                        reason: 'SE app-store promo',
                        dropped_content_summary: '3 buttons: 2 app-store hrefs + 1 promo label',
                    ),
                ],
            ],
        );

        $this->assertCount(1, $out);
        // The SE promo has no TeamLinkt equivalent — nothing gets placed.
        $this->assertNull($out[0]->teamlinkt_type);
        $this->assertSame(AssignmentDisposition::Superseded, $out[0]->disposition);
        $this->assertSame(TeamlinktBlockBucket::Platform, $out[0]->bucket);
        $this->assertStringContainsString('SE-promo', $out[0]->reason);
    }

    #[Test]
    public function captured_superseded_and_unmapped_are_three_distinct_dispositions(): void
    {
        // Three-in-one page: one content block, one platform block, one unknown block.
        $out = $this->assigner()->assign([
            'p1' => [
                'root' => ['title' => 'Mixed'],
                'content' => [
                    ['type' => 'Hero', 'props' => ['heading' => 'welcome']],
                    ['type' => 'PlatformNews', 'props' => ['org_id' => 'org-1']],
                    ['type' => 'MysteryFrame', 'props' => []],
                ],
            ],
        ]);

        $this->assertCount(3, $out);
        $dispositions = array_map(static fn ($a) => $a->disposition, $out);
        $this->assertSame(AssignmentDisposition::Captured, $dispositions[0]);
        $this->assertSame(AssignmentDisposition::Superseded, $dispositions[1]);
        $this->assertSame(AssignmentDisposition::Unmapped, $dispositions[2]);
    }
}
