<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Services\ContractEmitter\AssetLedger;
use App\Services\ContractEmitter\AssetContext;
use App\Services\ContractEmitter\PuckToContractMapper;
use App\Services\ContractEmitter\RichTextSanitizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Gap #6 in the ingest→generate silent-loss series (see CLAUDE.md
// "Known gaps"). SponsorGrid → `emitSponsors()` renders sponsor Cards
// as a `Sponsors` widget with NO image tokens — the scraped sponsor
// logo URLs are DISCARDED at the mapper before `AssetLedger::tokenFor`
// is ever called. Concrete symptom: langdondiamonds'
// `Remuda_Building_Supplies_Logo.svg` is in the source page_map but
// absent from the emitted contract JSON; no `svg_rasterizer_missing`
// or `svg_rasterization_failed` diagnostic fires either because the
// URL never reaches the ledger.
//
// This test is SKIPPED so the gap is visible in phpunit output rather
// than only in prose. Assertions describe the TARGET behavior (SVG URL
// IS tokenised, appears in `AssetLedger`, rasterised to PNG). When
// `emitSponsors()` is extended to route sponsor `image` props through
// the ledger (or a downstream reviewer changes the widget design so
// scraped logos survive), delete the `markTestSkipped` line and the
// assertions become the regression guard for the fix.
final class SponsorGridSvgNotTokenisedTest extends TestCase
{
    #[Test]
    public function sponsor_grid_svg_is_tokenised_through_asset_ledger(): void
    {
        $this->markTestSkipped(
            'GAP #6 (see CLAUDE.md Known Gaps): `PuckToContractMapper::emitSponsors()` '
            .'discards scraped sponsor logo URLs by widget design — the `Sponsors` '
            .'block emits with only an `id` prop, so `AssetLedger::tokenFor` is never '
            .'called on sponsor `Card.image` URLs. langdondiamonds\' '
            .'`Remuda_Building_Supplies_Logo.svg` is the concrete instance. '
            .'When the widget design is extended to preserve scraped sponsor logos, '
            .'unskip this test — the assertions below already spec the fix.'
        );

        // A Columns block with 3 sponsor Cards, one carrying an SVG
        // logo. This shape matches `looksLikeSponsorDeck`: 3 Cards,
        // majority with image + href.
        $svgSourceUrl = 'https://cdn.example.test/Remuda_Building_Supplies_Logo.svg';
        $puckData = [
            'content' => [
                [
                    'type' => 'Columns',
                    'props' => [
                        'columns' => [
                            ['children' => [[
                                'type' => 'Card',
                                'props' => [
                                    'title' => 'Remuda Building Supplies',
                                    'image' => $svgSourceUrl,
                                    'href' => 'https://remuda.example.test',
                                ],
                            ]]],
                            ['children' => [[
                                'type' => 'Card',
                                'props' => [
                                    'title' => 'Sponsor Two',
                                    'image' => 'https://cdn.example.test/two.png',
                                    'href' => 'https://two.example.test',
                                ],
                            ]]],
                            ['children' => [[
                                'type' => 'Card',
                                'props' => [
                                    'title' => 'Sponsor Three',
                                    'image' => 'https://cdn.example.test/three.png',
                                    'href' => 'https://three.example.test',
                                ],
                            ]]],
                        ],
                    ],
                ],
            ],
            'root' => new \stdClass,
            'zones' => new \stdClass,
        ];

        $mapper = $this->app->make(PuckToContractMapper::class);
        $ledger = new AssetLedger;
        $ctx = new AssetContext(orgId: 'ngin-test', pageUrl: 'https://source.test/sponsors');

        $result = $mapper->map(
            puckData: $puckData,
            ledger: $ledger,
            ctx: $ctx,
        );

        // TARGET: the SVG URL is registered with the ledger and
        // reachable by ref lookup. Currently NULL — hence the skip.
        $ref = $ledger->refForSource($svgSourceUrl);
        $this->assertNotNull(
            $ref,
            'sponsor SVG URL should have been registered with AssetLedger (currently discarded by `emitSponsors()`)'
        );

        // TARGET: the ledger declares an asset with mime `image/png`
        // (rasterised upstream by AssetPublisher via librsvg).
        $assets = $ledger->all()->toArray();
        $svgAsset = collect($assets)->firstWhere('sourceUrl', $svgSourceUrl);
        $this->assertNotNull($svgAsset, 'SVG source should appear in the ledger\'s declared assets');
        $this->assertSame('image/png', $svgAsset['mimeType'], 'SVG should have been rasterised to PNG');

        // TARGET: the emitted block should carry a `tl-asset:` token
        // that resolves back to the ledger ref. Under the current
        // widget behavior no such token exists.
        $emittedJson = json_encode($result);
        $this->assertStringContainsString(
            "tl-asset:{$ref}",
            (string) $emittedJson,
            'emitted Sponsors block should carry the tl-asset:<ref> token for the sponsor logo'
        );
    }
}
