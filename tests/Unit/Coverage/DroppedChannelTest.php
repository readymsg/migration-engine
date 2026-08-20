<?php

declare(strict_types=1);

namespace Tests\Unit\Coverage;

use App\Services\Coverage\CoverageReconciler;
use App\Services\Coverage\CoverageReport;
use App\Services\Coverage\SourceElementCounter;
use App\Services\Generate\BlockTypeAssigner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// The FAILURE CHANNEL test. The whole point of the coverage report is
// to make DROPPED source elements visible. If this test ever passes
// with an assertion that DROPPED is empty, the report has silently
// grown a matching mistake — the report would falsely claim 100%
// coverage of a fixture that deliberately drops content.
//
// The fixture: source markdown carries a distinctive phrase and a
// distinctive URL. The rebuilt page_map contains neither. No platform
// block, no scrub sidecar. Both MUST land in DROPPED with their
// snippets.
final class DroppedChannelTest extends TestCase
{
    private const DROPPED_PHRASE = 'A one-of-a-kind sentence about Zorblatt that appears nowhere in the rebuild.';

    private const DROPPED_URL = 'https://example.com/exclusive-registration-2026.pdf';

    #[Test]
    public function dropped_source_elements_surface_with_snippet_in_the_report(): void
    {
        $markdown = <<<'MD'
# Welcome

Sample intro paragraph that IS in the rebuild.

A one-of-a-kind sentence about Zorblatt that appears nowhere in the rebuild.

[Season registration form](https://example.com/exclusive-registration-2026.pdf)

MD;

        $pageMap = [
            'home' => [
                'root' => ['title' => 'Home'],
                'content' => [
                    ['type' => 'Hero', 'props' => ['heading' => 'Welcome']],
                    ['type' => 'Text', 'props' => ['body' => 'Sample intro paragraph that IS in the rebuild.']],
                ],
            ],
        ];

        $report = new CoverageReport(new BlockTypeAssigner, new SourceElementCounter, new CoverageReconciler);
        $md = $report->render(
            pageMap: $pageMap,
            pageTitles: [],
            pageMarkdown: ['home' => $markdown],
        );

        // The DROPPED section must exist AND carry BOTH the phrase snippet
        // AND the URL snippet. If either is absent, the failure channel
        // has been silenced.
        $this->assertStringContainsString('#### DROPPED (elements)', $md);
        $this->assertStringContainsString('Zorblatt', $md);
        $this->assertStringContainsString('exclusive-registration-2026.pdf', $md);

        // The consolidated site-wide DROPPED list must show up under the
        // top-level heading with its "ranked by frequency" table.
        $this->assertStringContainsString('DROPPED — source elements NOT preserved', $md);
        $this->assertStringContainsString('Consolidated by kind (ranked by frequency)', $md);

        // The site content-coverage ratio must be < 100% — otherwise a
        // matching regression has slipped through.
        // BOTH ratio labels must NOT be at 100% — the failure channel
        // must show up in both the headline migratable coverage AND the
        // raw capture rate.
        $this->assertDoesNotMatchRegularExpression('/Migratable coverage: 100\.0%/', $md);
        $this->assertDoesNotMatchRegularExpression('/Raw capture rate: 100\.0%/', $md);

        // And the DROPPED count in the site summary must be at least 2.
        $this->assertMatchesRegularExpression('/DROPPED \*\*(?:[2-9]|\d{2,})\*\*/', $md);

        // Structurally: no false claim that "no dropped elements on
        // this page" — that message must be absent when there ARE
        // dropped elements.
        $this->assertDoesNotMatchRegularExpression('/### Home.*?No dropped elements on this page/is', $md);
    }

    #[Test]
    public function out_of_scope_sponsor_strip_does_no_t_swallow_a_real_dropped_paragraph(): void
    {
        // Boundary test for Slice 3: a page that carries a sponsor
        // strip (out-of-scope) AND a genuinely-dropped org paragraph.
        // The sponsor rule must fire on the sponsor URL, and the org
        // paragraph MUST stay in DROPPED — scoping rules cannot become
        // a second silent-loss channel.
        $markdown = <<<'MD'
# Our Board

Meet the board.

- [![Sponsored by Dicks Sporting Goods](https://cdn1.sportngin.com/attachments/sponsor/d5a0-204690121/Store-Logo-DicksSportingGoods.png)](https://example.com)

A distinctive dropped paragraph about Zorblatt that has no equivalent in the rebuild whatsoever and must surface as DROPPED.

MD;

        $pageMap = [
            'board' => [
                'root' => ['title' => 'Our Board'],
                'content' => [
                    ['type' => 'Heading', 'props' => ['text' => 'Our Board', 'level' => 'h1']],
                    ['type' => 'Text', 'props' => ['body' => 'Meet the board.']],
                ],
            ],
        ];

        $report = new CoverageReport(new BlockTypeAssigner, new SourceElementCounter, new CoverageReconciler);
        $md = $report->render(
            pageMap: $pageMap,
            pageTitles: [],
            pageMarkdown: ['board' => $markdown],
        );

        // Sponsor logo URL MUST end up under OUT_OF_SCOPE.
        $this->assertMatchesRegularExpression(
            '/#### OUT_OF_SCOPE[^#]*sponsor strip/is',
            $md,
            'sponsor image must land in OUT_OF_SCOPE with the sponsor-strip category'
        );

        // Distinctive dropped paragraph MUST stay in DROPPED.
        $this->assertMatchesRegularExpression(
            '/#### DROPPED[^#]*Zorblatt/is',
            $md,
            'genuinely-dropped org paragraph must stay in DROPPED — scoping cannot swallow it'
        );

        // And the DROPPED count must be at least 1 (the Zorblatt line).
        $this->assertMatchesRegularExpression('/DROPPED \*\*[1-9]\d*\*\*/', $md);
    }

    #[Test]
    public function exclusion_rules_do_not_swallow_real_dropped_content(): void
    {
        // Mix: one genuinely-dropped element (the Zorblatt sentence)
        // AND one exclusion-worthy element (SE-platform prelive link).
        // Both are absent from the rebuild. The Zorblatt line MUST
        // still show up in DROPPED; the SE-platform link MUST show up
        // in EXCLUDED. If the SE-platform pattern accidentally
        // matched the Zorblatt line, the DROPPED count would go to 0
        // and this test would catch it.
        $markdown = <<<'MD'
# Welcome

A one-of-a-kind sentence about Zorblatt that appears nowhere in the rebuild.

[SE prelive nav](https://tbirdhoops.sportsengine-prelive.com/home)

MD;

        $pageMap = [
            'home' => [
                'root' => ['title' => 'Home'],
                'content' => [['type' => 'Hero', 'props' => ['heading' => 'Welcome']]],
            ],
        ];
        $report = new CoverageReport(new BlockTypeAssigner, new SourceElementCounter, new CoverageReconciler);
        $md = $report->render(
            pageMap: $pageMap,
            pageTitles: [],
            pageMarkdown: ['home' => $markdown],
        );

        $this->assertStringContainsString('Zorblatt', $md);
        $this->assertMatchesRegularExpression('/#### DROPPED[^#]*Zorblatt/is', $md, 'genuine dropped prose must surface as DROPPED, not EXCLUDED');
        $this->assertMatchesRegularExpression('/#### EXCLUDED[^#]*SE-platform chrome URL/is', $md, 'SE-platform link must surface as EXCLUDED');
    }
}
