<?php

declare(strict_types=1);

namespace Tests\Unit\ContractEmitter;

use App\Data\AssemblyBlockIssue;
use App\Data\AssemblyCoercion;
use App\Data\AssetRef;
use App\Data\Brand;
use App\Data\ConversionFailure;
use App\Data\ConversionResult;
use App\Data\ConversionStage;
use App\Data\ConversionStatus;
use App\Data\GlobalStyleBrief;
use App\Data\NavItem;
use App\Data\ResolvedNavItem;
use App\Data\ScrubIssue;
use App\Data\ScrubKind;
use App\Data\SiteImport\Diagnostic;
use App\Services\ContractEmitter\DiagnosticsCollector;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Optional;
use Tests\TestCase;

// Pins the diagnostics collector — turning every silent-loss
// surface into visible entries. Contract Part II: "Please use this
// generously."
final class DiagnosticsCollectorTest extends TestCase
{
    private DiagnosticsCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collector = new DiagnosticsCollector;
    }

    #[Test]
    public function conversion_failures_become_error_diagnostics_with_stage_in_code(): void
    {
        $result = $this->makeResult(failures: [
            new ConversionFailure(
                page_slug: 'page-1',
                page_title: 'Home',
                page_node_id: 100,
                stage: ConversionStage::BlockFill,
                reason: 'Sonnet 503',
            ),
        ]);
        $diags = $this->collector->collect($result);
        $this->assertCount(1, $diags);
        $this->assertSame('error', $diags[0]->severity);
        $this->assertStringStartsWith('stage_failure_', $diags[0]->code);
        $this->assertStringContainsString('block-fill', $diags[0]->code);
        $this->assertStringContainsString('page-1', $diags[0]->message);
    }

    #[Test]
    public function assembly_block_issues_become_warning_diagnostics(): void
    {
        $result = $this->makeResult(blockIssuesBySlug: [
            'page-2' => [
                new AssemblyBlockIssue(
                    block_index: 3,
                    component_type: 'Card',
                    coercion: AssemblyCoercion::Drop,
                    reason: 'Card.title empty',
                    path: 'props.title',
                ),
            ],
        ]);
        $diags = $this->collector->collect($result);
        $this->assertCount(1, $diags);
        $this->assertSame('warning', $diags[0]->severity);
        $this->assertStringContainsString('assembly_', $diags[0]->code);
        $this->assertStringContainsString('page-2', $diags[0]->message);
    }

    #[Test]
    public function scrub_issues_become_diagnostics_with_kind_based_codes(): void
    {
        $result = $this->makeResult(scrubIssuesBySlug: [
            'page-3' => [
                new ScrubIssue(
                    block_index: 0,
                    component_type: 'Hero',
                    kind: ScrubKind::HeroImageUnreachable,
                    reason: 'HTTP 403',
                    dropped_content_summary: 'url=x http=403',
                ),
                new ScrubIssue(
                    block_index: 5,
                    component_type: 'ButtonGroup',
                    kind: ScrubKind::SePromoHref,
                    reason: 'app-store link',
                    dropped_content_summary: '3 buttons: 2 app-store + 1 label',
                ),
            ],
        ]);
        $diags = $this->collector->collect($result);
        $this->assertCount(2, $diags);
        $codes = array_map(fn ($d) => $d->code, $diags);
        // Kind values used verbatim as codes so a reviewer can grep.
        $this->assertContains('hero_image_unreachable', $codes);
        $this->assertContains('se_promo_href', $codes);
        // droppedContent carried through when non-empty.
        foreach ($diags as $d) {
            if ($d->code === 'hero_image_unreachable') {
                $this->assertSame('url=x http=403', $d->droppedContent);
            }
        }
    }

    #[Test]
    public function hero_image_chosen_is_info_severity_not_warning(): void
    {
        // HeroImageChosen is a POSITIVE finding — the resolver made
        // a deliberate pick — not a drop. Recorded as info so it
        // doesn't clutter the warning noise.
        $result = $this->makeResult(scrubIssuesBySlug: [
            'page-1' => [
                new ScrubIssue(
                    block_index: 0,
                    component_type: 'Hero',
                    kind: ScrubKind::HeroImageChosen,
                    reason: 'kept block-fill pick',
                    dropped_content_summary: '',
                ),
            ],
        ]);
        $diags = $this->collector->collect($result);
        $this->assertCount(1, $diags);
        $this->assertSame('info', $diags[0]->severity);
    }

    #[Test]
    public function extras_from_mapper_are_appended_first(): void
    {
        // Slice 5/6/7 diagnostics come in via $extra. They arrive
        // first in the output so a reviewer sees stage-level context
        // before per-page issues.
        $extra = [
            new Diagnostic(severity: 'warning', code: 'columns_flattened', message: 'Grid deferred beyond M1'),
            new Diagnostic(severity: 'info', code: 'homepage_picked_by_fallback', message: 'no nav'),
        ];
        $result = $this->makeResult(failures: [
            new ConversionFailure('p', 't', null, ConversionStage::BlockFill, 'x'),
        ]);
        $diags = $this->collector->collect($result, $extra);
        $this->assertCount(3, $diags);
        $this->assertSame('columns_flattened', $diags[0]->code);
        $this->assertSame('homepage_picked_by_fallback', $diags[1]->code);
        $this->assertSame('stage_failure_block-fill', $diags[2]->code);
    }

    #[Test]
    public function empty_conversion_result_produces_no_diagnostics(): void
    {
        $diags = $this->collector->collect($this->makeResult());
        $this->assertCount(0, $diags);
    }

    #[Test]
    public function empty_scrub_summary_is_omitted_from_dropped_content(): void
    {
        $result = $this->makeResult(scrubIssuesBySlug: [
            'p' => [new ScrubIssue(0, 'Text', ScrubKind::SePromoLabel, 'r', '')],
        ]);
        $diags = $this->collector->collect($result);
        $this->assertInstanceOf(Optional::class, $diags[0]->droppedContent);
    }

    /**
     * @param  array<int, ConversionFailure>  $failures
     * @param  array<string, array<int, AssemblyBlockIssue>>  $blockIssuesBySlug
     * @param  array<string, array<int, ScrubIssue>>  $scrubIssuesBySlug
     */
    private function makeResult(
        array $failures = [],
        array $blockIssuesBySlug = [],
        array $scrubIssuesBySlug = [],
    ): ConversionResult {
        return new ConversionResult(
            conversion_id: 't',
            org_id: 'o',
            source_url: 'https://example.com',
            page_map: [],
            nav: new DataCollection(ResolvedNavItem::class, []),
            failures: new DataCollection(ConversionFailure::class, $failures),
            block_issues_by_slug: $blockIssuesBySlug,
            status: ConversionStatus::Completed,
            brand: new Brand(logo_source: 'flag', logo_asset_ref: null),
            style_brief: new GlobalStyleBrief(brand_voice: '', palette: [], layout_conventions: [], nav: new DataCollection(NavItem::class, [])),
            asset_refs: new DataCollection(AssetRef::class, []),
            scrub_issues_by_slug: $scrubIssuesBySlug,
        );
    }
}
