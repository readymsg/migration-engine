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
use App\Services\ContractEmitter\AssetLedger;
use App\Services\ContractEmitter\SiteSettingsEmitter;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Optional;
use Tests\TestCase;

// Pins the SiteSettings emitter against Contract Part II
// "What you may set on `site`" + the callout that primaryColor +
// neutralColor are the highest-value fields.
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

        $site = $this->emitter->emit($result, new AssetLedger);
        // Measured red, not LLM blue.
        $this->assertSame('#AE292E', $site->primaryColor);
        $this->assertSame('#5C5151', $site->neutralColor);
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
        $site = $this->emitter->emit($result, new AssetLedger);
        $this->assertSame('#AE292E', $site->primaryColor);
        // Measured lacks text → LLM fills.
        $this->assertSame('#1A1A1A', $site->neutralColor);
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
        $site = $this->emitter->emit($result, new AssetLedger);
        // Optional sentinel → omitted from JSON, not null.
        $this->assertInstanceOf(Optional::class, $site->primaryColor);
        $this->assertInstanceOf(Optional::class, $site->neutralColor);
    }

    // ─── siteName ───────────────────────────────────────────────────────

    #[Test]
    public function site_name_falls_back_to_source_host_when_voice_hint_missing(): void
    {
        $result = $this->makeResult(sourceUrl: 'https://www.tbirdhoops.org/');
        $site = $this->emitter->emit($result, new AssetLedger);
        // Host without the www. prefix.
        $this->assertSame('tbirdhoops.org', $site->siteName);
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
        $site = $this->emitter->emit($result, new AssetLedger);
        $this->assertSame('Thunderbird & Flight Basketball', $site->siteName);
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
        $site = $this->emitter->emit($result, $ledger);
        $this->assertIsString($site->logoUrl);
        $this->assertStringStartsWith('tl-asset:', $site->logoUrl);
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
        $site = $this->emitter->emit($result, $ledger);
        $this->assertInstanceOf(Optional::class, $site->logoUrl);
        $this->assertSame(0, $ledger->count());
    }

    #[Test]
    public function no_logo_source_url_omits_the_field(): void
    {
        $result = $this->makeResult(brand: new Brand(logo_source: 'flag', logo_asset_ref: null));
        $site = $this->emitter->emit($result, new AssetLedger);
        $this->assertInstanceOf(Optional::class, $site->logoUrl);
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
