<?php

declare(strict_types=1);

namespace Tests\Unit\ContractEmitter;

use App\Data\AssetRef;
use App\Data\Brand;
use App\Data\ConversionFailure;
use App\Data\ConversionResult;
use App\Data\ConversionStatus;
use App\Data\GlobalStyleBrief;
use App\Data\NavItem;
use App\Data\ResolvedNavItem;
use App\Data\SiteImport\Diagnostic;
use App\Services\ContractEmitter\AssetLedger;
use App\Services\ContractEmitter\SiteSettingsEmitter;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Optional;
use Tests\TestCase;

// Pins the SiteSettings emitter against Contract Part II
// "What you may set on `site`" + the callout that primaryColor +
// neutralColor are the highest-value fields.
//
// LOUD FALLBACK: every emit call produces exactly two palette-slot
// diagnostics (one for primary, one for neutral) so a silent
// fallback from measured to LLM on the highest-value fields
// surfaces immediately in the envelope. Slice-3 property.
final class SiteSettingsEmitterTest extends TestCase
{
    private SiteSettingsEmitter $emitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emitter = new SiteSettingsEmitter;
    }

    // ─── palette precedence ─────────────────────────────────────────────

    #[Test]
    public function measured_brand_palette_wins_over_llm_brief_palette(): void
    {
        // The load-bearing precedence rule from Slice B / the preview
        // stack: measured is deterministic and grounded in actual
        // logo bytes; LLM is a fresh guess per run.
        $result = $this->makeResult(
            brand: new Brand(
                logo_source: 'header',
                logo_asset_ref: null,
                palette: ['primary' => '#AE292E', 'text' => '#5C5151'],
            ),
            brief: new GlobalStyleBrief(
                brand_voice: '',
                palette: ['primary' => '#1F3A93', 'text' => '#1A1A1A'], // LLM guess
                layout_conventions: [],
                nav: new DataCollection(NavItem::class, []),
            ),
        );

        $emit = $this->emitter->emit($result, new AssetLedger);
        // Measured red, not LLM blue.
        $this->assertSame('#AE292E', $emit->settings->primaryColor);
        $this->assertSame('#5C5151', $emit->settings->neutralColor);
    }

    #[Test]
    public function llm_palette_fills_slots_the_measured_extractor_left_empty(): void
    {
        // If measured palette missed a slot (unusual — but possible
        // on a mono-hue brand), the LLM fills in.
        $result = $this->makeResult(
            brand: new Brand(
                logo_source: 'header',
                logo_asset_ref: null,
                palette: ['primary' => '#AE292E'], // no text
            ),
            brief: new GlobalStyleBrief(
                brand_voice: '',
                palette: ['primary' => '#1F3A93', 'text' => '#1A1A1A'],
                layout_conventions: [],
                nav: new DataCollection(NavItem::class, []),
            ),
        );
        $emit = $this->emitter->emit($result, new AssetLedger);
        $this->assertSame('#AE292E', $emit->settings->primaryColor);
        // Measured lacks text → LLM fills.
        $this->assertSame('#1A1A1A', $emit->settings->neutralColor);
    }

    #[Test]
    public function empty_palette_leaves_color_props_absent(): void
    {
        $result = $this->makeResult(
            brand: new Brand(logo_source: 'flag', logo_asset_ref: null, palette: []),
            brief: new GlobalStyleBrief(
                brand_voice: '',
                palette: [],
                layout_conventions: [],
                nav: new DataCollection(NavItem::class, []),
            ),
        );
        $emit = $this->emitter->emit($result, new AssetLedger);
        // Optional sentinel → omitted from JSON, not null.
        $this->assertInstanceOf(Optional::class, $emit->settings->primaryColor);
        $this->assertInstanceOf(Optional::class, $emit->settings->neutralColor);
    }

    // ─── siteName ───────────────────────────────────────────────────────

    #[Test]
    public function site_name_falls_back_to_source_host_when_voice_hint_missing(): void
    {
        $result = $this->makeResult(sourceUrl: 'https://www.tbirdhoops.org/');
        $emit = $this->emitter->emit($result, new AssetLedger);
        // Host without the www. prefix.
        $this->assertSame('tbirdhoops.org', $emit->settings->siteName);
    }

    #[Test]
    public function voice_hint_wins_over_host_fallback(): void
    {
        $result = $this->makeResult(
            sourceUrl: 'https://www.tbirdhoops.org/',
            brand: new Brand(
                logo_source: 'header',
                logo_asset_ref: null,
                palette: [],
                voice_hint: 'Thunderbird & Flight Basketball',
            ),
        );
        $emit = $this->emitter->emit($result, new AssetLedger);
        $this->assertSame('Thunderbird & Flight Basketball', $emit->settings->siteName);
    }

    // ─── logo tokenisation ──────────────────────────────────────────────

    #[Test]
    public function logo_source_url_becomes_tl_asset_token_registered_with_ledger(): void
    {
        $result = $this->makeResult(brand: new Brand(
            logo_source: 'header',
            logo_asset_ref: 's3://engine-bucket/orgs/x/logos/abc.png',
            logo_source_url: 'https://cdn2.sportngin.com/attachments/banner_graphic/aa/siteHeader.png',
            palette: [],
        ));
        $ledger = new AssetLedger;
        $emit = $this->emitter->emit($result, $ledger);
        $this->assertIsString($emit->settings->logoUrl);
        $this->assertStringStartsWith('tl-asset:', $emit->settings->logoUrl);
        // Ledger holds the ORIGINAL CDN URL, not our s3_key.
        $this->assertSame(1, $ledger->count());
        $assets = $ledger->all()->items();
        $this->assertSame(
            'https://cdn2.sportngin.com/attachments/banner_graphic/aa/siteHeader.png',
            $assets[0]->sourceUrl,
        );
        $this->assertSame('logo', $assets[0]->usage);
    }

    #[Test]
    public function svg_logo_is_dropped_not_declared(): void
    {
        // AssetLedger rejects SVGs (Contract Part II — stored-XSS
        // vector). Emitter treats the null return as "no logo" and
        // omits the field; caller collects a diagnostic via the
        // ledger's rejection reason (Slice 8).
        $result = $this->makeResult(brand: new Brand(
            logo_source: 'header',
            logo_asset_ref: null,
            logo_source_url: 'https://cdn.example.com/logo.svg',
            palette: [],
        ));
        $ledger = new AssetLedger;
        $emit = $this->emitter->emit($result, $ledger);
        $this->assertInstanceOf(Optional::class, $emit->settings->logoUrl);
        $this->assertSame(0, $ledger->count());
    }

    #[Test]
    public function no_logo_source_url_omits_the_field(): void
    {
        $result = $this->makeResult(brand: new Brand(logo_source: 'flag', logo_asset_ref: null));
        $emit = $this->emitter->emit($result, new AssetLedger);
        $this->assertInstanceOf(Optional::class, $emit->settings->logoUrl);
    }

    // ─── loud fallback diagnostics (Slice 3) ────────────────────────────

    #[Test]
    public function measured_palette_emits_info_diagnostic_naming_measured_source(): void
    {
        $result = $this->makeResult(
            brand: new Brand(
                logo_source: 'header',
                logo_asset_ref: null,
                palette: ['primary' => '#AE292E', 'text' => '#5C5151'],
            ),
            brief: new GlobalStyleBrief(
                brand_voice: '',
                palette: [],
                layout_conventions: [],
                nav: new DataCollection(NavItem::class, []),
            ),
        );
        $emit = $this->emitter->emit($result, new AssetLedger);
        $codes = array_map(fn (Diagnostic $d) => $d->code, $emit->diagnostics);
        $this->assertSame(
            ['palette_primary_from_measured', 'palette_neutral_from_measured'],
            $codes,
            'both slots must emit an info diagnostic naming the measured source',
        );
        foreach ($emit->diagnostics as $d) {
            $this->assertSame('info', $d->severity);
            $this->assertStringContainsString('LogoPaletteExtractor', $d->message);
        }
    }

    #[Test]
    public function llm_fallback_emits_warning_diagnostic_with_measurement_error_reason(): void
    {
        // The load-bearing property: silent measured→LLM fallback on
        // the highest-value fields in the payload must surface as a
        // WARNING diagnostic naming the reason the measured source was
        // unavailable. Contract Part II calls primaryColor/neutralColor
        // the highest-value fields.
        $result = $this->makeResult(
            brand: new Brand(
                logo_source: 'header',
                logo_asset_ref: null,
                palette: [],
                palette_error: 'logo_fetch_failed: HTTP 404',
            ),
            brief: new GlobalStyleBrief(
                brand_voice: '',
                palette: ['primary' => '#1F3A93', 'text' => '#1A1A1A'],
                layout_conventions: [],
                nav: new DataCollection(NavItem::class, []),
            ),
        );
        $emit = $this->emitter->emit($result, new AssetLedger);

        $codes = array_map(fn (Diagnostic $d) => $d->code, $emit->diagnostics);
        $this->assertSame(
            ['palette_primary_from_llm_guess', 'palette_neutral_from_llm_guess'],
            $codes,
        );
        foreach ($emit->diagnostics as $d) {
            $this->assertSame('warning', $d->severity);
            $this->assertStringContainsString('logo_fetch_failed: HTTP 404', $d->message);
            $this->assertStringContainsString('highest-value field', $d->message);
        }
    }

    #[Test]
    public function llm_fallback_without_measurement_attempt_names_no_logo_measured(): void
    {
        // No logo captured at all (flag path) → palette_error is null.
        // The diagnostic should still surface the fallback but with a
        // 'no_logo_measured' reason so a reviewer can tell "measurement
        // failed" apart from "no logo to measure."
        $result = $this->makeResult(
            brand: new Brand(logo_source: 'flag', logo_asset_ref: null),
            brief: new GlobalStyleBrief(
                brand_voice: '',
                palette: ['primary' => '#1F3A93', 'text' => '#1A1A1A'],
                layout_conventions: [],
                nav: new DataCollection(NavItem::class, []),
            ),
        );
        $emit = $this->emitter->emit($result, new AssetLedger);
        foreach ($emit->diagnostics as $d) {
            $this->assertSame('warning', $d->severity);
            $this->assertStringContainsString('no_logo_measured', $d->message);
        }
    }

    #[Test]
    public function missing_palette_from_both_sources_emits_warning_missing_diagnostic(): void
    {
        // Neither measured nor LLM provided any color. SiteSettings
        // slot is Optional, but the reviewer needs to know.
        $result = $this->makeResult(
            brand: new Brand(logo_source: 'flag', logo_asset_ref: null, palette: []),
            brief: new GlobalStyleBrief(
                brand_voice: '',
                palette: [],
                layout_conventions: [],
                nav: new DataCollection(NavItem::class, []),
            ),
        );
        $emit = $this->emitter->emit($result, new AssetLedger);
        $codes = array_map(fn (Diagnostic $d) => $d->code, $emit->diagnostics);
        $this->assertSame(['palette_primary_missing', 'palette_neutral_missing'], $codes);
        foreach ($emit->diagnostics as $d) {
            $this->assertSame('warning', $d->severity);
        }
    }

    #[Test]
    public function per_slot_source_split_measured_primary_llm_neutral(): void
    {
        // Realistic mono-hue case: measured caught the primary but not
        // text. Diagnostics should split (info for primary, warning for
        // neutral) — the whole point of per-slot signals is to attribute
        // each slot independently.
        $result = $this->makeResult(
            brand: new Brand(
                logo_source: 'header',
                logo_asset_ref: null,
                palette: ['primary' => '#AE292E'],
            ),
            brief: new GlobalStyleBrief(
                brand_voice: '',
                palette: ['text' => '#1A1A1A'],
                layout_conventions: [],
                nav: new DataCollection(NavItem::class, []),
            ),
        );
        $emit = $this->emitter->emit($result, new AssetLedger);
        $this->assertCount(2, $emit->diagnostics);
        $this->assertSame('palette_primary_from_measured', $emit->diagnostics[0]->code);
        $this->assertSame('info', $emit->diagnostics[0]->severity);
        $this->assertSame('palette_neutral_from_llm_guess', $emit->diagnostics[1]->code);
        $this->assertSame('warning', $emit->diagnostics[1]->severity);
    }

    // ─── helpers ────────────────────────────────────────────────────────

    private function makeResult(
        ?Brand $brand = null,
        ?GlobalStyleBrief $brief = null,
        string $sourceUrl = 'https://www.example.com/',
    ): ConversionResult {
        return new ConversionResult(
            conversion_id: 'test',
            org_id: 'test-org',
            source_url: $sourceUrl,
            page_map: [],
            nav: new DataCollection(ResolvedNavItem::class, []),
            failures: new DataCollection(ConversionFailure::class, []),
            block_issues_by_slug: [],
            status: ConversionStatus::Completed,
            brand: $brand ?? new Brand(logo_source: 'flag', logo_asset_ref: null),
            style_brief: $brief ?? new GlobalStyleBrief(
                brand_voice: '',
                palette: [],
                layout_conventions: [],
                nav: new DataCollection(NavItem::class, []),
            ),
            asset_refs: new DataCollection(AssetRef::class, []),
        );
    }
}
