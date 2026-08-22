<?php

declare(strict_types=1);

namespace Tests\Unit\ContractEmitter;

use App\Data\SiteImport\Asset;
use App\Data\SiteImport\Block;
use App\Data\SiteImport\Diagnostic;
use App\Data\SiteImport\Envelope;
use App\Data\SiteImport\Page;
use App\Data\SiteImport\PageData;
use App\Data\SiteImport\SiteSettings;
use App\Data\SiteImport\Source;
use App\Services\ContractEmitter\BlockDeltaAuditor;
use App\Services\ContractEmitter\MapperAudit;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

// Pins the automatic block-delta reconciliation. Ask verbatim:
//   "Any block that can't be attributed is itself a diagnostic."
final class BlockDeltaAuditorTest extends TestCase
{
    private BlockDeltaAuditor $auditor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditor = new BlockDeltaAuditor;
    }

    #[Test]
    public function clean_reconciliation_emits_info_summary_only(): void
    {
        $sourcePageMap = [
            'home' => [
                'content' => [
                    ['type' => 'Text', 'props' => ['body' => '<p>Body</p>']],
                    ['type' => 'Hero', 'props' => ['heading' => 'H']],
                ],
            ],
        ];
        $envelope = $this->envelopeWithBlocks([
            new Block(type: 'Text', props: ['id' => 't1', 'body' => 'Body']),
            new Block(type: 'Hero', props: ['id' => 'h1', 'heading' => 'H']),
        ]);
        $audit = new MapperAudit;
        $audit->record('map_text', 1, 1);
        $audit->record('map_hero', 1, 1);

        $report = $this->auditor->audit($sourcePageMap, $envelope, $audit);
        $this->assertTrue($report->isReconciled());
        $diagnostics = $this->auditor->toDiagnostics($report);
        $codes = array_map(fn ($d) => $d->code, $diagnostics);
        $this->assertContains('block_delta_summary', $codes);
        $this->assertNotContains('block_delta_unaccounted', $codes);
    }

    #[Test]
    public function output_mismatch_fires_error_diagnostic(): void
    {
        // Envelope has 2 blocks; mapper reported emitting only 1.
        // Simulates a silent-drop path where a block appeared in
        // the output without being recorded by the mapper.
        $sourcePageMap = ['home' => ['content' => [['type' => 'Text', 'props' => ['body' => 'x']]]]];
        $envelope = $this->envelopeWithBlocks([
            new Block(type: 'Text', props: ['id' => 't1', 'body' => 'One']),
            new Block(type: 'Text', props: ['id' => 't2', 'body' => 'Two']),
        ]);
        $audit = new MapperAudit;
        $audit->record('map_text', 1, 1);

        $report = $this->auditor->audit($sourcePageMap, $envelope, $audit);
        $this->assertFalse($report->isReconciled());
        $this->assertSame(1, $report->outputMismatch);
        $codes = array_map(fn ($d) => $d->code, $this->auditor->toDiagnostics($report));
        $this->assertContains('block_delta_unaccounted', $codes);
    }

    #[Test]
    public function input_mismatch_fires_error_diagnostic(): void
    {
        // Source has 2 Text blocks; mapper reported seeing only 1.
        // Simulates a silent-skip path.
        $sourcePageMap = [
            'home' => ['content' => [
                ['type' => 'Text', 'props' => ['body' => 'One']],
                ['type' => 'Text', 'props' => ['body' => 'Two']],
            ]],
        ];
        $envelope = $this->envelopeWithBlocks([
            new Block(type: 'Text', props: ['id' => 't1', 'body' => 'One']),
        ]);
        $audit = new MapperAudit;
        $audit->record('map_text', 1, 1);

        $report = $this->auditor->audit($sourcePageMap, $envelope, $audit);
        $this->assertFalse($report->isReconciled());
        $this->assertSame(1, $report->inputMismatch);
    }

    #[Test]
    public function columns_wrappers_do_not_count_as_input_content(): void
    {
        // Columns is a container — consumed by the mapper. Its
        // children ARE counted.
        $sourcePageMap = [
            'home' => ['content' => [
                ['type' => 'Columns', 'props' => ['columns' => [
                    ['children' => [
                        ['type' => 'Text', 'props' => ['body' => 'Left']],
                    ]],
                    ['children' => [
                        ['type' => 'Text', 'props' => ['body' => 'Right']],
                    ]],
                ]]],
            ]],
        ];
        $envelope = $this->envelopeWithBlocks([
            new Block(type: 'Grid', props: [
                'id' => 'g1',
                'columns' => '2',
                'column1' => [['type' => 'Text', 'props' => ['id' => 't1', 'body' => 'Left']]],
                'column2' => [['type' => 'Text', 'props' => ['id' => 't2', 'body' => 'Right']]],
            ]),
        ]);
        $audit = new MapperAudit;
        $audit->record('map_text', 1, 1);
        $audit->record('map_text', 1, 1);
        $audit->record('columns_wrap_grid', 0, 1);

        $report = $this->auditor->audit($sourcePageMap, $envelope, $audit);
        $this->assertSame(2, $report->blocksIn, 'Columns wrapper does NOT count; only its 2 Text children do');
        $this->assertSame(3, $report->blocksOut, 'Grid + 2 slot children = 3 deep blocks');
        $this->assertTrue($report->isReconciled());
    }

    #[Test]
    public function button_group_input_counts_each_button(): void
    {
        // ButtonGroup is 1 source block, but the mapper unfolds it
        // to N per-button Buttons. Input counter treats each button
        // as a source block so the fanout reconciles.
        $sourcePageMap = [
            'home' => ['content' => [
                ['type' => 'ButtonGroup', 'props' => ['buttons' => [
                    ['label' => 'A', 'href' => '/a'],
                    ['label' => 'B', 'href' => '/b'],
                    ['label' => 'C', 'href' => '/c'],
                ]]],
            ]],
        ];
        $envelope = $this->envelopeWithBlocks([
            new Block(type: 'Button', props: ['id' => 'b1', 'label' => 'A', 'href' => '/a']),
            new Block(type: 'Button', props: ['id' => 'b2', 'label' => 'B', 'href' => '/b']),
            new Block(type: 'Button', props: ['id' => 'b3', 'label' => 'C', 'href' => '/c']),
        ]);
        $audit = new MapperAudit;
        $audit->record('map_button_group', 3, 3);

        $report = $this->auditor->audit($sourcePageMap, $envelope, $audit);
        $this->assertSame(3, $report->blocksIn);
        $this->assertSame(3, $report->blocksOut);
        $this->assertTrue($report->isReconciled());
    }

    /**
     * @param  array<int, Block>  $blocks
     */
    private function envelopeWithBlocks(array $blocks): Envelope
    {
        return new Envelope(
            schemaVersion: 1,
            source: new Source(url: 'https://x.com', scrapedAt: '2026-08-21T00:00:00Z', pagesDiscovered: 1, pagesMapped: 1),
            site: new SiteSettings,
            pages: new DataCollection(Page::class, [
                new Page(
                    id: 'home',
                    slug: '',
                    title: 'Home',
                    parentId: null,
                    navOrder: 0,
                    showInNav: true,
                    data: new PageData(content: new DataCollection(Block::class, $blocks)),
                ),
            ]),
            assets: new DataCollection(Asset::class, []),
            diagnostics: new DataCollection(Diagnostic::class, []),
        );
    }
}
