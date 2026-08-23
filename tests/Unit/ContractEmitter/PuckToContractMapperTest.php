<?php

declare(strict_types=1);

namespace Tests\Unit\ContractEmitter;

use App\Data\AssetRef;
use App\Data\SiteImport\Block;
use App\Services\ContractEmitter\AssetContext;
use App\Services\ContractEmitter\AssetLedger;
use App\Services\ContractEmitter\ContractSchema;
use App\Services\ContractEmitter\ContractSchemaValidator;
use App\Services\ContractEmitter\PuckToContractMapper;
use App\Services\ContractEmitter\RichTextSanitizer;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

// Pins the old-schema-PuckOutput → contract-Block[] mapping for the
// M1 palette. Every mapping is proven both structurally (correct
// contract block emitted with correct prop names + types) AND via
// the Slice 2 validator (mapper output is contract-legal by
// construction, not just by inspection).
final class PuckToContractMapperTest extends TestCase
{
    private PuckToContractMapper $mapper;

    private ContractSchemaValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new PuckToContractMapper(new RichTextSanitizer);
        $this->validator = new ContractSchemaValidator(ContractSchema::load());
    }

    // ─── Text → Text ────────────────────────────────────────────────────

    #[Test]
    public function old_text_maps_to_contract_text_with_sanitised_body(): void
    {
        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => '<p>Hello <script>alert(1)</script> world</p>', 'align' => 'left']]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertCount(1, $out->blocks);
        $this->assertSame('Text', $out->blocks[0]->type);
        $this->assertSame('p', $out->blocks[0]->props['as']);
        // <script> content stripped by the sanitiser.
        $this->assertStringNotContainsString('script', (string) $out->blocks[0]->props['body']);
        $this->assertStringNotContainsString('alert', (string) $out->blocks[0]->props['body']);
        $this->assertStringContainsString('Hello', (string) $out->blocks[0]->props['body']);
        $this->assertValidates($out->blocks);
    }

    // ─── Heading → Text with as ─────────────────────────────────────────

    #[Test]
    public function old_heading_level_2_maps_to_text_as_h2(): void
    {
        $out = $this->mapper->mapContent(
            [['type' => 'Heading', 'props' => ['level' => 2, 'text' => 'About Us']]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertCount(1, $out->blocks);
        $b = $out->blocks[0];
        $this->assertSame('Text', $b->type);
        $this->assertSame('h2', $b->props['as']);
        $this->assertSame('About Us', $b->props['body']);
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function old_heading_level_1_downgrades_to_h2(): void
    {
        // Contract Part II: "One `h1` per page belongs to the page;
        // blocks own their own heading hierarchy below that."
        $out = $this->mapper->mapContent(
            [['type' => 'Heading', 'props' => ['level' => 1, 'text' => 'Page Title']]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('h2', $out->blocks[0]->props['as']);
    }

    #[Test]
    public function old_heading_levels_4_5_6_collapse_to_h3(): void
    {
        // Contract Text.as caps at h3.
        foreach ([4, 5, 6] as $level) {
            $out = $this->mapper->mapContent(
                [['type' => 'Heading', 'props' => ['level' => $level, 'text' => 'Deep']]],
                $this->assetContext(),
                new AssetLedger,
            );
            $this->assertSame('h3', $out->blocks[0]->props['as'], "level {$level} must collapse to h3");
        }
    }

    // ─── Hero → Hero (imageUrl rename + tl-asset token) ─────────────────

    #[Test]
    public function old_hero_background_image_becomes_hero_image_url_with_tl_asset_token(): void
    {
        // The exact rename that broke our old preview into wrong-
        // prop-name territory. background_image → imageUrl.
        $refs = new DataCollection(AssetRef::class, [
            new AssetRef(
                s3_key: 's3://engine-bucket/orgs/x/logos/abc.jpg',
                mime_type: 'image/jpeg',
                source_url: 'https://cdn2.sportngin.com/attachments/banner_graphic/aa/hero.jpg',
            ),
        ]);
        $ctx = new AssetContext($refs);
        $ledger = new AssetLedger;

        $out = $this->mapper->mapContent(
            [['type' => 'Hero', 'props' => [
                'background_image' => 's3://engine-bucket/orgs/x/logos/abc.jpg',
                'heading' => 'Welcome',
            ]]],
            $ctx,
            $ledger,
        );

        $this->assertCount(1, $out->blocks);
        $hero = $out->blocks[0];
        $this->assertSame('Hero', $hero->type);
        // Contract's imageUrl, not our old background_image.
        $this->assertArrayHasKey('imageUrl', $hero->props);
        $this->assertArrayNotHasKey('background_image', $hero->props);
        // Token, not raw URL.
        $this->assertStringStartsWith('tl-asset:', (string) $hero->props['imageUrl']);
        // Ledger got the ORIGINAL sourceUrl, not our s3 key.
        $this->assertSame(1, $ledger->count());
        $assets = $ledger->all()->items();
        $this->assertSame('https://cdn2.sportngin.com/attachments/banner_graphic/aa/hero.jpg', $assets[0]->sourceUrl);
        $this->assertSame('hero', $assets[0]->usage);
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function hero_with_unresolvable_image_emits_hero_without_url_plus_diagnostic(): void
    {
        // Contract Hero has no required props — imageUrl is optional
        // and template default fills. So an unresolvable background
        // should still emit the Hero (heading survives) but record
        // a warning diagnostic.
        $out = $this->mapper->mapContent(
            [['type' => 'Hero', 'props' => [
                'background_image' => '/preview-assets?p=unknown',
                'heading' => 'Welcome',
            ]]],
            $this->assetContext(),
            new AssetLedger,
        );

        $this->assertCount(1, $out->blocks);
        $this->assertArrayNotHasKey('imageUrl', $out->blocks[0]->props);
        $this->assertSame('Welcome', $out->blocks[0]->props['heading']);
        $this->assertCount(1, $out->diagnostics);
        $this->assertSame('hero_image_unresolvable', $out->diagnostics[0]->code);
        $this->assertValidates($out->blocks);
    }

    // ─── Image → Image (src → tl-asset:) ────────────────────────────────

    #[Test]
    public function old_image_src_becomes_tl_asset_token(): void
    {
        $refs = new DataCollection(AssetRef::class, [
            new AssetRef(
                s3_key: 's3://x/photos/pic.png',
                mime_type: 'image/png',
                source_url: 'https://cdn.example.com/photo.png',
            ),
        ]);
        $ctx = new AssetContext($refs);
        $ledger = new AssetLedger;

        $out = $this->mapper->mapContent(
            [['type' => 'Image', 'props' => [
                'src' => 's3://x/photos/pic.png',
                'alt' => 'Team photo',
                'caption' => 'Championship 2025',
            ]]],
            $ctx,
            $ledger,
        );

        $this->assertCount(1, $out->blocks);
        $img = $out->blocks[0];
        $this->assertSame('Image', $img->type);
        $this->assertStringStartsWith('tl-asset:', (string) $img->props['src']);
        $this->assertSame('Team photo', $img->props['alt']);
        $this->assertSame('Championship 2025', $img->props['caption']);
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function image_with_unresolvable_src_is_dropped_with_diagnostic(): void
    {
        $out = $this->mapper->mapContent(
            [['type' => 'Image', 'props' => ['src' => '/preview-assets?p=missing']]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertCount(0, $out->blocks, 'image with unresolvable src must be dropped');
        $this->assertCount(1, $out->diagnostics);
        $this->assertSame('image_asset_unresolvable', $out->diagnostics[0]->code);
    }

    // ─── Gallery → Gallery (items → images) ─────────────────────────────

    #[Test]
    public function old_gallery_items_maps_to_contract_gallery_images(): void
    {
        $refs = new DataCollection(AssetRef::class, [
            new AssetRef(s3_key: 's3://x/1.jpg', mime_type: 'image/jpeg', source_url: 'https://cdn.example.com/1.jpg'),
            new AssetRef(s3_key: 's3://x/2.jpg', mime_type: 'image/jpeg', source_url: 'https://cdn.example.com/2.jpg'),
        ]);
        $ctx = new AssetContext($refs);
        $ledger = new AssetLedger;

        $out = $this->mapper->mapContent(
            [['type' => 'Gallery', 'props' => [
                'title' => 'Season Photos',
                'items' => [
                    ['src' => 's3://x/1.jpg', 'alt' => 'First', 'caption' => 'One'],
                    ['src' => 's3://x/2.jpg', 'alt' => 'Second', 'caption' => 'Two'],
                ],
            ]]],
            $ctx,
            $ledger,
        );

        $this->assertCount(1, $out->blocks);
        $g = $out->blocks[0];
        $this->assertSame('Gallery', $g->type);
        // Contract's images[], not our old items[].
        $this->assertArrayHasKey('images', $g->props);
        $this->assertArrayNotHasKey('items', $g->props);
        $this->assertIsArray($g->props['images']);
        $this->assertCount(2, $g->props['images']);
        $this->assertStringStartsWith('tl-asset:', $g->props['images'][0]['src']);
        $this->assertSame('First', $g->props['images'][0]['alt']);
        $this->assertSame('One', $g->props['images'][0]['caption']);
        $this->assertSame('Season Photos', $g->props['heading']);
        $this->assertSame(2, $ledger->count());
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function gallery_with_all_items_unresolvable_is_dropped(): void
    {
        $out = $this->mapper->mapContent(
            [['type' => 'Gallery', 'props' => [
                'items' => [
                    ['src' => '/preview-assets?p=x1'],
                    ['src' => '/preview-assets?p=x2'],
                ],
            ]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertCount(0, $out->blocks);
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('gallery_all_items_unresolvable', $codes);
    }

    // ─── ButtonGroup → sequence of Button ───────────────────────────────

    #[Test]
    public function old_button_group_becomes_sequence_of_contract_buttons(): void
    {
        $out = $this->mapper->mapContent(
            [['type' => 'ButtonGroup', 'props' => [
                'buttons' => [
                    ['label' => 'Register', 'href' => '/register', 'variant' => 'solid'],
                    ['label' => 'Learn more', 'href' => '/about', 'variant' => 'outline'],
                ],
            ]]],
            $this->assetContext(),
            new AssetLedger,
        );

        $this->assertCount(2, $out->blocks);
        $this->assertSame('Button', $out->blocks[0]->type);
        $this->assertSame('Register', $out->blocks[0]->props['label']);
        $this->assertSame('/register', $out->blocks[0]->props['href']);
        $this->assertSame('solid', $out->blocks[0]->props['variant']);
        $this->assertSame('Button', $out->blocks[1]->type);
        $this->assertSame('outline', $out->blocks[1]->props['variant']);
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function button_without_label_or_href_is_dropped(): void
    {
        // Label-less button: silent-loss risk (would render as
        // "Button" default). Href-less button: dead link. Both drop
        // WITH a partial diagnostic (per-item drops are noted).
        $out = $this->mapper->mapContent(
            [['type' => 'ButtonGroup', 'props' => [
                'buttons' => [
                    ['label' => 'Register', 'href' => '/register'],
                    ['label' => '', 'href' => '/x'], // no label
                    ['label' => 'Learn', 'href' => ''], // no href
                ],
            ]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertCount(1, $out->blocks, 'only the fully-populated button survives');
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('button_group_partial', $codes, 'partial-drop must be diagnostic-visible');
    }

    // ─── silent-loss guards ─────────────────────────────────────────────

    #[Test]
    public function button_group_with_no_survivors_emits_dropped_diagnostic(): void
    {
        $out = $this->mapper->mapContent(
            [['type' => 'ButtonGroup', 'props' => [
                'buttons' => [
                    ['label' => '', 'href' => '/x'],
                    ['label' => 'Learn', 'href' => ''],
                ],
            ]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertCount(0, $out->blocks);
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('button_group_dropped_empty', $codes);
    }

    #[Test]
    public function empty_heading_emits_dropped_diagnostic(): void
    {
        $out = $this->mapper->mapContent(
            [['type' => 'Heading', 'props' => ['level' => 2, 'text' => '']]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertCount(0, $out->blocks);
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('heading_dropped_empty', $codes);
    }

    #[Test]
    public function text_devoured_by_sanitiser_emits_dropped_diagnostic(): void
    {
        // Body was non-empty in source (had markup) but the TipTap-
        // subset stripper reduced it to nothing. Reviewer should
        // see because that markup existed and now it's gone.
        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => '<script>alert(1)</script>']]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertCount(0, $out->blocks);
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('text_body_sanitised_to_empty', $codes);
    }

    #[Test]
    public function text_with_genuinely_empty_body_is_silent_noop(): void
    {
        // An empty-body Text was no-op in source too. No diagnostic —
        // that would be noise.
        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => '']]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertCount(0, $out->blocks);
        $this->assertCount(0, $out->diagnostics);
    }

    #[Test]
    public function card_with_unresolvable_image_only_emits_dropped_diagnostic(): void
    {
        // Card whose only field is an image, and that image is
        // unresolvable. Card produces zero blocks (all-fields-empty
        // after resolution). Must be visible.
        $out = $this->mapper->mapContent(
            [['type' => 'Card', 'props' => ['image' => '/preview-assets?p=unknown']]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertCount(0, $out->blocks);
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('card_image_unresolvable', $codes);
        $this->assertContains('card_dropped_no_survivable_content', $codes);
    }

    // ─── Card → Text + Image + Text + Button unfolding ──────────────────

    #[Test]
    public function old_card_unfolds_to_h3_title_image_body_button(): void
    {
        $refs = new DataCollection(AssetRef::class, [
            new AssetRef(s3_key: 's3://x/card.jpg', mime_type: 'image/jpeg', source_url: 'https://cdn.example.com/card.jpg'),
        ]);
        $ctx = new AssetContext($refs);
        $ledger = new AssetLedger;

        $out = $this->mapper->mapContent(
            [['type' => 'Card', 'props' => [
                'title' => 'Fall Registration',
                'body' => '<p>Register now for the fall season.</p>',
                'image' => 's3://x/card.jpg',
                'href' => '/register',
            ]]],
            $ctx,
            $ledger,
        );

        // Order: Text(h3, title), Image, Text(p, body), Button.
        $this->assertGreaterThanOrEqual(4, count($out->blocks));
        $this->assertSame('Text', $out->blocks[0]->type);
        $this->assertSame('h3', $out->blocks[0]->props['as']);
        $this->assertSame('Fall Registration', $out->blocks[0]->props['body']);
        $this->assertSame('Image', $out->blocks[1]->type);
        $this->assertSame('Text', $out->blocks[2]->type);
        $this->assertSame('p', $out->blocks[2]->props['as']);
        $this->assertSame('Button', $out->blocks[3]->type);
        $this->assertSame('Learn more', $out->blocks[3]->props['label']);
        $this->assertValidates($out->blocks);
    }

    // ─── Slice 13: Columns of Cards → TeamMembers ───────────────────────

    #[Test]
    public function columns_of_people_cards_board_shape_fold_to_team_members(): void
    {
        // Board-style Cards: title=name, body=role, image=photo.
        // 3+ Cards with images should fold to a single TeamMembers.
        $refs = new DataCollection(AssetRef::class, [
            new AssetRef(s3_key: 's3://x/scott.jpg', mime_type: 'image/jpeg', source_url: 'https://cdn.example.com/scott.jpg'),
            new AssetRef(s3_key: 's3://x/eric.jpg', mime_type: 'image/jpeg', source_url: 'https://cdn.example.com/eric.jpg'),
            new AssetRef(s3_key: 's3://x/dana.jpg', mime_type: 'image/jpeg', source_url: 'https://cdn.example.com/dana.jpg'),
        ]);
        $ctx = new AssetContext($refs);
        $out = $this->mapper->mapContent(
            [['type' => 'Columns', 'props' => [
                'columns' => [
                    ['children' => [['type' => 'Card', 'props' => ['title' => 'Scott Whitenack', 'body' => 'President', 'image' => 's3://x/scott.jpg']]]],
                    ['children' => [['type' => 'Card', 'props' => ['title' => 'Eric Debord', 'body' => 'Coordinator', 'image' => 's3://x/eric.jpg']]]],
                    ['children' => [['type' => 'Card', 'props' => ['title' => 'Dana Whitfield', 'body' => 'Treasurer', 'image' => 's3://x/dana.jpg']]]],
                ],
            ]]],
            $ctx,
            new AssetLedger,
        );
        $this->assertCount(1, $out->blocks);
        $tm = $out->blocks[0];
        $this->assertSame('TeamMembers', $tm->type);
        $this->assertSame(3, $tm->props['columns']); // preserved from source
        $items = $tm->props['items'];
        $this->assertCount(3, $items);
        $this->assertSame('Scott Whitenack', $items[0]['name']);
        $this->assertSame('President', $items[0]['role']);
        $this->assertStringStartsWith('tl-asset:', $items[0]['photo']);
        // Diagnostic emitted for the fold.
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('columns_folded_to_team_members', $codes);
        // The columns_flattened diagnostic must NOT fire (we didn't
        // flatten — we folded).
        $this->assertNotContains('columns_flattened', $codes);
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function columns_of_people_cards_contacts_shape_fold_to_team_members(): void
    {
        // Contacts-style Cards: title=role, body="name\nemail\nphone",
        // image empty. Must parse the multi-line body correctly.
        $out = $this->mapper->mapContent(
            [['type' => 'Columns', 'props' => [
                'columns' => [
                    ['children' => [['type' => 'Card', 'props' => ['title' => 'President', 'body' => "Scott Whitenack\nswhitenack@cinci.rr.com\n📞 513-702-4623"]]]],
                    ['children' => [['type' => 'Card', 'props' => ['title' => 'Treasurer', 'body' => "Janet Habedank\ntbirdhoopstreasurer@gmail.com\n📞 513-460-7753"]]]],
                    ['children' => [['type' => 'Card', 'props' => ['title' => 'Scheduling', 'body' => "Kristin Peoples\nkristinolversonpeoples@gmail.com"]]]],
                ],
            ]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertCount(1, $out->blocks);
        $items = $out->blocks[0]->props['items'];
        $this->assertCount(3, $items);
        // Name extracted from first non-email line.
        $this->assertSame('Scott Whitenack', $items[0]['name']);
        $this->assertSame('President', $items[0]['role']);
        $this->assertSame('swhitenack@cinci.rr.com', $items[0]['email']);
        // Phone line preserved as bio.
        $this->assertStringContainsString('513-702-4623', $items[0]['bio']);
        // Third item has no phone line → empty bio.
        $this->assertSame('Kristin Peoples', $items[2]['name']);
        $this->assertSame('kristinolversonpeoples@gmail.com', $items[2]['email']);
        $this->assertSame('', $items[2]['bio']);
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function google_maps_images_place_one_locations_widget_per_page(): void
    {
        // Slice 15d: Google Maps static-map image URLs (no fetchable
        // file extension → previously image_asset_unresolvable
        // dropped) now trigger a single Locations widget at page
        // top level. Individual map Images are consumed.
        $mapUrl1 = 'https://maps.googleapis.com/maps/api/js/StaticMapService.GetMapImage?token=1';
        $mapUrl2 = 'https://maps.googleapis.com/maps/api/js/StaticMapService.GetMapImage?token=2';
        $out = $this->mapper->mapContent(
            [
                ['type' => 'Text', 'props' => ['body' => '<p>Our facilities</p>']],
                ['type' => 'Image', 'props' => ['src' => $mapUrl1, 'alt' => 'Facility 1 map']],
                ['type' => 'Image', 'props' => ['src' => $mapUrl2, 'alt' => 'Facility 2 map']],
            ],
            $this->assetContext(),
            new AssetLedger,
        );

        // ONE Locations widget emitted at the top; two Image blocks
        // consumed; the Text survives unchanged.
        $types = array_map(fn ($b) => $b->type, $out->blocks);
        $this->assertSame(['Locations', 'Text'], $types);
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('locations_widget_placed', $codes);
        // No image_asset_unresolvable diagnostics — those were only
        // emitted by mapImage, which we no longer call for map URLs.
        $this->assertNotContains('image_asset_unresolvable', $codes);
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function google_maps_images_nested_in_columns_also_consumed_by_page_locations(): void
    {
        // Facilities shape: map images inside Columns children. Only
        // ONE Locations widget emitted at page top (not one per
        // Grid slot).
        $mapUrl = 'https://maps.googleapis.com/maps/api/js/StaticMapService.GetMapImage?token=x';
        $out = $this->mapper->mapContent(
            [
                ['type' => 'Columns', 'props' => [
                    'columns' => [
                        ['children' => [['type' => 'Image', 'props' => ['src' => $mapUrl, 'alt' => 'map']]]],
                        ['children' => [['type' => 'Image', 'props' => ['src' => $mapUrl.'2', 'alt' => 'map2']]]],
                    ],
                ]],
            ],
            $this->assetContext(),
            new AssetLedger,
        );
        $types = array_map(fn ($b) => $b->type, $out->blocks);
        // Locations at top, then Grid (with empty-after-map-filter
        // columns → sparse omission).
        $this->assertContains('Locations', $types);
        // Only ONE Locations block per page.
        $locationsCount = count(array_filter($out->blocks, fn ($b) => $b->type === 'Locations'));
        $this->assertSame(1, $locationsCount, 'exactly one Locations widget per page');
    }

    #[Test]
    public function columns_of_news_article_cards_place_a_newslist_widget(): void
    {
        // News-shape Cards: title=headline, body=prose summary,
        // image=photo, href=real https:// URL. Distinct from
        // sponsor-shape by long-body + real-URL signals.
        $out = $this->mapper->mapContent(
            [['type' => 'Columns', 'props' => [
                'columns' => [
                    ['children' => [['type' => 'Card', 'props' => [
                        'title' => 'Tryouts Season Recap',
                        'body' => 'Our fall tryouts wrapped up last weekend with over 200 athletes participating across all divisions. The board sends its thanks to every volunteer coach.',
                        'href' => 'https://www.tbirdhoops.org/news/tryouts-2025',
                    ]]]],
                    ['children' => [['type' => 'Card', 'props' => [
                        'title' => 'Championship Win',
                        'body' => 'The U16 boys division won the regional championship this weekend after a hard-fought season. Congratulations to Coach Whitenack and the entire team.',
                        'href' => 'https://www.tbirdhoops.org/news/u16-championship',
                    ]]]],
                    ['children' => [['type' => 'Card', 'props' => [
                        'title' => 'Registration Now Open',
                        'body' => 'Spring registration opened this week. Early-bird pricing available through March 15 — visit the registration page to secure your spot in the program.',
                        'href' => 'https://www.tbirdhoops.org/news/spring-registration',
                    ]]]],
                ],
            ]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertCount(1, $out->blocks);
        $this->assertSame('NewsList', $out->blocks[0]->type);
        // Widget = placement only; scraped content NOT leaked into props.
        $this->assertArrayNotHasKey('items', $out->blocks[0]->props);
        $this->assertArrayNotHasKey('resolvedItems', $out->blocks[0]->props);
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('news_list_placed_widget', $codes);
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function columns_of_sponsor_cards_place_a_sponsors_widget(): void
    {
        // Slice 15b: sponsor-deck detection. What Slice 13 correctly
        // REJECTED as a people directory now becomes a Sponsors
        // widget instead of flattening. Widgets are placed, never
        // filled — scraped logos + URLs discarded; a diagnostic
        // records what was in source.
        $refs = new DataCollection(AssetRef::class, [
            new AssetRef(s3_key: 's3://x/logo1.png', mime_type: 'image/png', source_url: 'https://cdn.example.com/logo1.png'),
            new AssetRef(s3_key: 's3://x/logo2.png', mime_type: 'image/png', source_url: 'https://cdn.example.com/logo2.png'),
            new AssetRef(s3_key: 's3://x/logo3.png', mime_type: 'image/png', source_url: 'https://cdn.example.com/logo3.png'),
        ]);
        $out = $this->mapper->mapContent(
            [['type' => 'Columns', 'props' => [
                'columns' => [
                    ['children' => [['type' => 'Card', 'props' => ['title' => 'Dicks Sporting Goods', 'body' => 'Visit Website', 'image' => 's3://x/logo1.png', 'href' => 'https://www.dickssportinggoods.com/']]]],
                    ['children' => [['type' => 'Card', 'props' => ['title' => 'Become a sponsor', 'body' => 'Want to support youth basketball?', 'image' => 's3://x/logo2.png', 'href' => '#']]]],
                    ['children' => [['type' => 'Card', 'props' => ['title' => 'Become a sponsor', 'body' => 'Support Lakota Thunderbird.', 'image' => 's3://x/logo3.png', 'href' => '#']]]],
                ],
            ]]],
            new AssetContext($refs),
            new AssetLedger,
        );
        $this->assertCount(1, $out->blocks);
        $this->assertSame('Sponsors', $out->blocks[0]->type);
        // No leaked scraped-content props on the widget.
        $this->assertArrayNotHasKey('items', $out->blocks[0]->props);
        $this->assertArrayNotHasKey('logos', $out->blocks[0]->props);
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('sponsor_deck_placed_widget', $codes);
        $this->assertNotContains('columns_flattened', $codes);
        $this->assertNotContains('TeamMembers', array_map(fn ($b) => $b->type, $out->blocks));
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function two_card_columns_does_no_t_fold_to_team_members(): void
    {
        // Threshold ≥ 3 rejects two-card layouts (About Us shape:
        // "Boys 3rd–6th Flight Teams — contact Michael at ...").
        $out = $this->mapper->mapContent(
            [['type' => 'Columns', 'props' => [
                'columns' => [
                    ['children' => [['type' => 'Card', 'props' => ['title' => 'Boys Teams', 'body' => 'Contact Michael at mlewis@tbirdhoops.org']]]],
                    ['children' => [['type' => 'Card', 'props' => ['title' => 'Girls Teams', 'body' => 'Contact Eric at eric@example.com']]]],
                ],
            ]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $types = array_map(fn ($b) => $b->type, $out->blocks);
        $this->assertNotContains('TeamMembers', $types);
    }

    // ─── Columns → Grid (Slice 15c replaces the flatten path) ───────────

    #[Test]
    public function old_columns_maps_to_grid_with_slot_children_preserved(): void
    {
        // Slice 15c replaced the columns_flattened path with Grid
        // emission. Layout is now RESTORED — no more single-column
        // stacks for non-people, non-sponsor Columns.
        $out = $this->mapper->mapContent(
            [['type' => 'Columns', 'props' => [
                'columns' => [
                    ['children' => [
                        ['type' => 'Text', 'props' => ['body' => '<p>Left</p>']],
                    ]],
                    ['children' => [
                        ['type' => 'Text', 'props' => ['body' => '<p>Right</p>']],
                    ]],
                ],
            ]]],
            $this->assetContext(),
            new AssetLedger,
        );

        $this->assertCount(1, $out->blocks);
        $grid = $out->blocks[0];
        $this->assertSame('Grid', $grid->type);
        // Source had 2 columns → Grid.columns="2".
        $this->assertSame('2', $grid->props['columns']);
        // Slot children preserved (Block objects, not flattened).
        $this->assertArrayHasKey('column1', $grid->props);
        $this->assertArrayHasKey('column2', $grid->props);
        $this->assertCount(1, $grid->props['column1']);
        $this->assertCount(1, $grid->props['column2']);
        // Slot children stored as array form (not Block objects) so
        // downstream walkers can traverse via array_walk_recursive.
        $this->assertSame('Text', $grid->props['column1'][0]['type']);
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('columns_mapped_to_grid', $codes);
        $this->assertNotContains('columns_flattened', $codes, 'columns_flattened is retired');
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function grid_clamps_source_columns_over_four_to_four_with_diagnostic(): void
    {
        // Contract Grid.columns caps at 4. Source with 6 columns
        // should pack the extras into column4 with a diagnostic.
        $out = $this->mapper->mapContent(
            [['type' => 'Columns', 'props' => [
                'columns' => array_map(
                    fn (int $i) => ['children' => [['type' => 'Text', 'props' => ['body' => "<p>Col {$i}</p>"]]]],
                    range(1, 6),
                ),
            ]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertCount(1, $out->blocks);
        $this->assertSame('4', $out->blocks[0]->props['columns']);
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('grid_columns_clamped', $codes);
    }

    // ─── unmappable types ───────────────────────────────────────────────

    #[Test]
    public function unknown_old_type_becomes_diagnostic_not_block(): void
    {
        $out = $this->mapper->mapContent(
            [['type' => 'Widget', 'props' => []]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertCount(0, $out->blocks);
        $this->assertCount(1, $out->diagnostics);
        $this->assertSame('unmappable_block_type', $out->diagnostics[0]->code);
    }

    // ─── deterministic block ids ────────────────────────────────────────

    #[Test]
    public function block_ids_are_deterministic_for_same_content(): void
    {
        $input = [['type' => 'Text', 'props' => ['body' => '<p>Same</p>']]];
        $a = $this->mapper->mapContent($input, $this->assetContext(), new AssetLedger);
        $b = $this->mapper->mapContent($input, $this->assetContext(), new AssetLedger);
        $this->assertSame($a->blocks[0]->props['id'], $b->blocks[0]->props['id']);
        $this->assertMatchesRegularExpression('/^text-[a-z0-9]{6}$/', (string) $a->blocks[0]->props['id']);
    }

    // ─── validator + mapper compose: mapper never emits invalid blocks ─

    #[Test]
    public function mapper_never_emits_a_block_the_validator_rejects(): void
    {
        // A representative mixed page — every old-block type at least once —
        // must produce a fully-validating contract Block[]. This is the
        // load-bearing "translation is contract-correct BY CONSTRUCTION"
        // property that Slice 9's payload emitter will lean on.
        $refs = new DataCollection(AssetRef::class, [
            new AssetRef(s3_key: 's3://x/hero.jpg', mime_type: 'image/jpeg', source_url: 'https://cdn.example.com/hero.jpg'),
            new AssetRef(s3_key: 's3://x/gal1.jpg', mime_type: 'image/jpeg', source_url: 'https://cdn.example.com/gal1.jpg'),
        ]);
        $ctx = new AssetContext($refs);

        $out = $this->mapper->mapContent(
            [
                ['type' => 'Hero', 'props' => ['background_image' => 's3://x/hero.jpg', 'heading' => 'Welcome']],
                ['type' => 'Heading', 'props' => ['level' => 2, 'text' => 'About']],
                ['type' => 'Text', 'props' => ['body' => '<p>Body <strong>text</strong>.</p>']],
                ['type' => 'Gallery', 'props' => ['items' => [['src' => 's3://x/gal1.jpg', 'alt' => 'g']]]],
                ['type' => 'ButtonGroup', 'props' => ['buttons' => [['label' => 'Register', 'href' => '/register']]]],
            ],
            $ctx,
            new AssetLedger,
        );

        $this->assertValidates($out->blocks);
    }

    // ─── video fold ─────────────────────────────────────────────────────

    #[Test]
    public function cjfl_home_youtube_embed_folds_to_video(): void
    {
        // cjfl Home has the Grayson Statham "Under The Helmet" YouTube
        // embed. Block-fill currently drops the URL when it emits a
        // Card summary of the section — the new IR concept prompts it
        // to preserve the URL inside a Text so the mapper can fold.
        $body = '### Video Zone: Around the League'."\n".
                '[CJFL Under The Helmet - Grayson Statham](https://www.youtube.com/watch?v=cZ7WGdTMdUY)';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );

        $this->assertCount(1, $out->blocks);
        $this->assertSame('Video', $out->blocks[0]->type);
        $this->assertSame('https://www.youtube.com/watch?v=cZ7WGdTMdUY', $out->blocks[0]->props['url']);
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('text_body_folded_to_video', $codes);
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function bare_youtu_be_url_folds_to_video(): void
    {
        // Short form URL, no markdown link syntax.
        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => 'https://youtu.be/dQw4w9WgXcQ']]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('Video', $out->blocks[0]->type);
        $this->assertSame('https://youtu.be/dQw4w9WgXcQ', $out->blocks[0]->props['url']);
    }

    #[Test]
    public function vimeo_url_also_folds_to_video(): void
    {
        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => '[Season highlights](https://vimeo.com/123456789)']]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('Video', $out->blocks[0]->type);
        $this->assertSame('https://vimeo.com/123456789', $out->blocks[0]->props['url']);
    }

    // ─── video NEGATIVE tests ───────────────────────────────────────────

    #[Test]
    public function prose_paragraph_with_inline_youtube_url_stays_as_text(): void
    {
        // Any hero-paragraph-length body with a YouTube URL mentioned
        // mid-prose must NOT fold. The ≤3-line compact-body gate holds.
        $body = "The Canadian Junior Football League has a rich broadcast history. Games are streamed weekly across our platform.\n\n".
                "Watch the latest highlight reel at https://www.youtube.com/watch?v=cZ7WGdTMdUY where fans can catch every touchdown.\n\n".
                "Our media partners provide ongoing coverage throughout the season, ensuring fans stay connected wherever they are.\n\n".
                'Additional archives are available on request.';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('Text', $out->blocks[0]->type);
    }

    #[Test]
    public function non_video_url_link_body_does_not_fold(): void
    {
        // A body with only a link to a non-video URL is NOT a video
        // block. This is FeatureGrid or file_download territory.
        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => '[About Us](https://www.cjfl.org/about)']]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertNotSame('Video', $out->blocks[0]->type);
    }

    // ─── file_download fold ─────────────────────────────────────────────

    #[Test]
    public function single_heading_pdf_link_folds_to_file_download(): void
    {
        // cjfl Rules & Regulations includes one-doc-heading pages; the
        // "CJFL Records" page has 2 doc-link headings + no other content.
        // A single-doc-heading text body folds to FileDownload; the
        // multi-doc-heading page still folds to FeatureGrid (≥3 rule).
        $body = '### [CJFL Rules and Regulations](https://cdn3.sportngin.com/attachments/document/0068/6004/CJFL_Rules_Regs_April_2014.pdf)';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );

        $this->assertCount(1, $out->blocks);
        $this->assertSame('FileDownload', $out->blocks[0]->type);
        $this->assertSame('CJFL Rules and Regulations', $out->blocks[0]->props['label']);
        $this->assertStringEndsWith('.pdf', $out->blocks[0]->props['fileUrl']);
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('text_body_folded_to_file_download', $codes);
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function single_bulleted_pptx_link_folds_to_file_download(): void
    {
        // langdon For Coaches uses .pptx and .docx extensions too.
        $body = '- [Coach Clinic Training](https://cdn3.sportngin.com/attachments/document/0d5e-2802916/Coach_s_Clinc_Slide_Deck_2023.pptx)';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('FileDownload', $out->blocks[0]->type);
        $this->assertStringEndsWith('.pptx', $out->blocks[0]->props['fileUrl']);
    }

    // ─── file_download NEGATIVE tests ───────────────────────────────────

    #[Test]
    public function multi_document_link_body_still_folds_to_feature_grid(): void
    {
        // The langdon For Coaches shape (9 doc-link headings) MUST fold
        // to FeatureGrid, not become 9 FileDownload blocks. The ≥3
        // items gate is what protects this — FileDownload only fires
        // on exactly-one-line bodies.
        $body = '### [Coach Clinic Training](https://x.example/clinic.pptx)'."\n".
                '### [T-Ball Coaches Handbook](https://x.example/tball.pdf)'."\n".
                '### [Coach Pitch Program](https://x.example/cp.pdf)';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('FeatureGrid', $out->blocks[0]->type);
        $this->assertNotSame('FileDownload', $out->blocks[0]->type);
    }

    #[Test]
    public function heading_link_to_html_page_stays_as_text_not_file_download(): void
    {
        // The URL doesn't end in a document extension — this is a
        // regular navigation link, not a download.
        $body = '### [About Us](https://x.example/about)';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('Text', $out->blocks[0]->type);
    }

    #[Test]
    public function inline_pdf_reference_in_prose_stays_as_text(): void
    {
        // Prose that references a PDF mid-sentence — not a download
        // block. The exactly-one-line gate keeps this out.
        $body = 'Please review the rules PDF at [this link](https://x.example/rules.pdf) '.
                'before signing up. All players must comply.';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('Text', $out->blocks[0]->type);
    }

    // ─── feature_grid fold ──────────────────────────────────────────────

    #[Test]
    public function cjfl_awards_link_heading_body_folds_to_feature_grid(): void
    {
        // cjfl Awards. Block-fill emits Awards' 13 link-headings as
        // one Text block with a bulleted list of `- [text](url)` items.
        // The mapper folds to a FeatureGrid.
        $body = '- [Canadian Bowl Champions](https://www.cjfl.org/page/show/1286366-canadian-bowl-champions)'."\n".
                '- [Gord Currie Coach of the Year](https://www.cjfl.org/page/show/1286341-gord-currie-coach-of-the-year)'."\n".
                '- [Rookie of the Year](https://www.cjfl.org/page/show/1286342-rookie-of-the-year)'."\n".
                '- [Larry Wruck Defensive Player of the Year](https://www.cjfl.org/page/show/1286343-larry-wruck-defensive-player-of-the-year)'."\n".
                '- [Peter Dalla Riva Offensive Player of the Year](https://www.cjfl.org/page/show/1286344-peter-dalla-riva-offensive-player-of-the-year)';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );

        $this->assertCount(1, $out->blocks);
        $this->assertSame('FeatureGrid', $out->blocks[0]->type);
        $items = $out->blocks[0]->props['items'];
        $this->assertCount(5, $items);
        $this->assertSame('Canadian Bowl Champions', $items[0]['title']);
        $this->assertStringContainsString('cjfl.org', $items[0]['body']);
        // Columns clamps to [2,3,4] — 5 items → 3.
        $this->assertSame(3, $out->blocks[0]->props['columns']);
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('text_body_folded_to_feature_grid', $codes);
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function langdon_for_coaches_heading_link_body_folds_to_feature_grid(): void
    {
        // langdon For Coaches. Block-fill emits `### [Text](url)`
        // headings as one Text block. Same fold.
        $body = '### [Coach Clinic Training](https://cdn3.sportngin.com/attachments/document/0d5e-2802916/Coach_s_Clinc_Slide_Deck_2023.pptx)'."\n".
                '### [T-Ball Coaches Handbook](https://cdn4.sportngin.com/attachments/document/b1b4-2786436/Langdon_Little_League_-_T-Ball_Coach_Handbook.pdf)'."\n".
                '### [Coach Pitch Program](https://cdn4.sportngin.com/attachments/document/24df-2921331/Coach_Pitch_Program.pdf)';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('FeatureGrid', $out->blocks[0]->type);
        $this->assertCount(3, $out->blocks[0]->props['items']);
    }

    #[Test]
    public function optional_section_heading_becomes_feature_grid_heading(): void
    {
        // A leading non-link `## Awards` heading followed by link-only
        // lines is treated as the FeatureGrid.heading prop.
        $body = '## Awards'."\n".
                '- [Award A](https://x.example/a)'."\n".
                '- [Award B](https://x.example/b)'."\n".
                '- [Award C](https://x.example/c)';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('FeatureGrid', $out->blocks[0]->type);
        $this->assertSame('Awards', $out->blocks[0]->props['heading']);
        $this->assertCount(3, $out->blocks[0]->props['items']);
    }

    // ─── feature_grid NEGATIVE tests (must NOT regress existing folds) ──

    #[Test]
    public function sponsor_deck_in_columns_still_folds_to_sponsors_widget(): void
    {
        // LOAD-BEARING: Sponsors detection lives in mapColumns on Card
        // sequences with image+href. Our new mapText fold operates on
        // a different code path — must not regress this. The pattern:
        // Columns → 3+ Cards each with image + href → Sponsors widget.
        $refs = new DataCollection(AssetRef::class, [
            new AssetRef(s3_key: 's3://x/sponsor1.png', mime_type: 'image/png', source_url: 'https://cdn.x/sponsor1.png'),
            new AssetRef(s3_key: 's3://x/sponsor2.png', mime_type: 'image/png', source_url: 'https://cdn.x/sponsor2.png'),
            new AssetRef(s3_key: 's3://x/sponsor3.png', mime_type: 'image/png', source_url: 'https://cdn.x/sponsor3.png'),
        ]);
        $ctx = new AssetContext($refs);

        $out = $this->mapper->mapContent(
            [[
                'type' => 'Columns',
                'props' => ['columns' => [
                    ['children' => [
                        ['type' => 'Card', 'props' => ['title' => 'Dicks Sporting Goods', 'image' => 's3://x/sponsor1.png', 'href' => 'https://dicks.example.com']],
                    ]],
                    ['children' => [
                        ['type' => 'Card', 'props' => ['title' => 'Baron Rings', 'image' => 's3://x/sponsor2.png', 'href' => 'https://baron.example.com']],
                    ]],
                    ['children' => [
                        ['type' => 'Card', 'props' => ['title' => 'Cleland Contracting', 'image' => 's3://x/sponsor3.png', 'href' => 'https://cleland.example.com']],
                    ]],
                ]],
            ]],
            $ctx,
            new AssetLedger,
        );

        $this->assertCount(1, $out->blocks);
        $this->assertSame('Sponsors', $out->blocks[0]->type);
        $this->assertNotSame('FeatureGrid', $out->blocks[0]->type);
    }

    #[Test]
    public function people_directory_in_columns_still_folds_to_team_members_widget(): void
    {
        // Same posture: TeamMembers detection on Card sequences with
        // photo + name + role. Must not regress to FeatureGrid.
        $refs = new DataCollection(AssetRef::class, [
            new AssetRef(s3_key: 's3://x/p1.png', mime_type: 'image/png', source_url: 'https://cdn.x/p1.png'),
            new AssetRef(s3_key: 's3://x/p2.png', mime_type: 'image/png', source_url: 'https://cdn.x/p2.png'),
            new AssetRef(s3_key: 's3://x/p3.png', mime_type: 'image/png', source_url: 'https://cdn.x/p3.png'),
        ]);
        $ctx = new AssetContext($refs);

        $out = $this->mapper->mapContent(
            [[
                'type' => 'Columns',
                'props' => ['columns' => [
                    ['children' => [
                        ['type' => 'Card', 'props' => ['title' => 'President', 'body' => 'Scott Whitenack', 'image' => 's3://x/p1.png']],
                    ]],
                    ['children' => [
                        ['type' => 'Card', 'props' => ['title' => 'Secretary', 'body' => 'Kristin Peoples', 'image' => 's3://x/p2.png']],
                    ]],
                    ['children' => [
                        ['type' => 'Card', 'props' => ['title' => 'Treasurer', 'body' => 'Janet Habedank', 'image' => 's3://x/p3.png']],
                    ]],
                ]],
            ]],
            $ctx,
            new AssetLedger,
        );

        $this->assertCount(1, $out->blocks);
        $this->assertSame('TeamMembers', $out->blocks[0]->type);
        $this->assertNotSame('FeatureGrid', $out->blocks[0]->type);
    }

    #[Test]
    public function prose_with_occasional_link_stays_as_text_not_feature_grid(): void
    {
        // Any non-blank line that isn't link-only disqualifies. Real
        // scraped prose with mid-paragraph links must NOT fold.
        $body = 'For more information, see [our history page](https://x/history) or contact us. '.
                'Registration is open [here](https://x/register). '.
                'Read the [rules](https://x/rules) before signing up.';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('Text', $out->blocks[0]->type);
    }

    #[Test]
    public function two_link_headings_below_min_stays_as_text(): void
    {
        // Under the min-3 threshold.
        $body = '- [Rules](https://x/rules)'."\n".
                '- [Register](https://x/register)';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('Text', $out->blocks[0]->type);
    }

    // ─── qa_section fold — FAQ vs Accordion branch by page context ──────

    #[Test]
    public function langdon_for_parents_qa_section_folds_to_accordion(): void
    {
        // langdon For Parents — Q&A section INSIDE a broader page. Slug
        // /forparents doesn't indicate FAQ, so the mapper folds to
        // Accordion, NOT FAQ. Contract guidance: "FAQ for a dedicated
        // FAQ page; Accordion for expandable sections inside another
        // page."
        $body = "## Frequently Asked Questions\n\n".
                "**What dates and times do each Division play and where?**\n\n".
                "- Blast Ball: Tuesday & Thursday from 6-6:45pm\n".
                "- T-Ball: Tuesdays and Thursdays from 6-7pm\n\n".
                "**What are the general dates for the Baseball Season?**\n\n".
                "Regular Season: May - June (Play offs end of June)\n\n".
                "**What Division would my child be based on Age?**\n\n".
                "- Blast Ball - Age 3-4\n".
                "- T-Ball - Age 5-6\n".
                "- Coach Pitch - Ages 7-8\n\n".
                "**What is the Cost per division?**\n\n".
                'Blast Ball - $150.00. T-Ball - $150.00.';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
            sourcePageUrl: 'https://www.langdondiamonds.ca/forparents',
        );

        $this->assertCount(1, $out->blocks);
        $this->assertSame('Accordion', $out->blocks[0]->type);
        $items = $out->blocks[0]->props['items'];
        $this->assertCount(4, $items);
        $this->assertSame('What dates and times do each Division play and where?', $items[0]['title']);
        $this->assertStringContainsString('Blast Ball', $items[0]['body']);
        $this->assertSame('What is the Cost per division?', $items[3]['title']);

        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('text_body_folded_to_accordion', $codes);
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function dedicated_faq_page_folds_to_faq(): void
    {
        // Synthetic pin — no site in the corpus has a dedicated FAQ
        // page today, but the branch is real and reachable. Slug
        // /faq is the signal.
        $body = "## Frequently Asked Questions\n\n".
                "**How do I register?**\n\n".
                "Registration opens each spring on the Registration page.\n\n".
                "**Are there refunds?**\n\n".
                "Refunds are available up to two weeks before season start.\n\n".
                "**When does the season start?**\n\n".
                'Season starts in early May.';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
            sourcePageUrl: 'https://example.org/faq',
        );

        $this->assertCount(1, $out->blocks);
        $this->assertSame('FAQ', $out->blocks[0]->type);
        $this->assertCount(3, $out->blocks[0]->props['items']);
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('text_body_folded_to_faq', $codes);
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function frequently_asked_questions_slug_variant_also_maps_to_faq(): void
    {
        // Also accept /frequently-asked-questions and /faqs slug forms.
        $body = "**Do you have refunds?**\n\n".
                "Yes.\n\n".
                "**Can I transfer to another team?**\n\n".
                "Yes, contact your coordinator.\n\n".
                "**Do you offer coaching clinics?**\n\n".
                'Yes, twice a season.';

        foreach (['https://example.org/frequently-asked-questions', 'https://example.org/faqs'] as $url) {
            $out = $this->mapper->mapContent(
                [['type' => 'Text', 'props' => ['body' => $body]]],
                $this->assetContext(),
                new AssetLedger,
                sourcePageUrl: $url,
            );
            $this->assertSame('FAQ', $out->blocks[0]->type, "url {$url} must map to FAQ");
        }
    }

    #[Test]
    public function three_question_markers_alone_are_enough_without_heading(): void
    {
        // Body-level detection: no explicit "Frequently Asked Questions"
        // heading, but 3+ bold-question markers still trigger. Slug
        // isn't FAQ, so Accordion.
        $body = "**Do I need my own equipment?**\n\n".
                "Yes, players supply their own bat, glove, and cleats.\n\n".
                "**What age divisions do you offer?**\n\n".
                "Ages 5 through 18, split into six divisions.\n\n".
                "**Where can I register?**\n\n".
                'Registration opens on the Registration TAB in early March.';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
            sourcePageUrl: 'https://example.org/parents',
        );
        $this->assertSame('Accordion', $out->blocks[0]->type);
        $this->assertCount(3, $out->blocks[0]->props['items']);
    }

    #[Test]
    public function two_question_markers_with_heading_is_enough(): void
    {
        // Lower threshold (2 items) when an explicit FAQ heading is present.
        // Non-FAQ slug → Accordion.
        $body = "## FAQ\n\n".
                "**When are practices?**\n\n".
                "Tuesdays and Thursdays.\n\n".
                "**Where are practices held?**\n\n".
                'Iron Horse Fields.';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
            sourcePageUrl: 'https://example.org/parents',
        );
        $this->assertSame('Accordion', $out->blocks[0]->type);
        $this->assertCount(2, $out->blocks[0]->props['items']);
    }

    #[Test]
    public function accordion_body_is_richtext_sanitised(): void
    {
        // Both Accordion.items[].body and FAQ.items[].body are in the
        // x-teamlinkt.vocabularies.richtext.props list — the sanitiser
        // applies uniformly. HTML in the answer must be TipTap-vocab
        // clean when it lands.
        $body = "**Do I need registration?**\n\n".
                "<p>Yes. <script>alert(1)</script>Please sign up early.</p>\n\n".
                "**What are the fees?**\n\n".
                "See the <table><tr><td>fee</td></tr></table> table below.\n\n".
                "**When is the season?**\n\n".
                'Summer.';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
            sourcePageUrl: 'https://example.org/info',
        );
        $this->assertSame('Accordion', $out->blocks[0]->type);
        foreach ($out->blocks[0]->props['items'] as $item) {
            $this->assertStringNotContainsString('<script', $item['body']);
            $this->assertStringNotContainsString('<table', $item['body']);
        }
    }

    // ─── qa_section NEGATIVE tests ──────────────────────────────────────

    #[Test]
    public function two_questions_without_heading_stays_as_text(): void
    {
        // Below the standalone threshold of 3, and no explicit FAQ
        // heading — this is normal prose with two rhetorical questions.
        $body = "**When are practices?**\n\n".
                "Tuesdays and Thursdays.\n\n".
                "**Where are practices held?**\n\n".
                'Iron Horse Fields.';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('Text', $out->blocks[0]->type);
    }

    #[Test]
    public function inline_bold_with_question_marks_stays_as_text(): void
    {
        // `**Bold?**` mid-sentence is inline emphasis, not a question
        // heading. Body-level match requires whole-line questions —
        // this should stay as Text so prose doesn't false-fold.
        $body = 'Some parents wonder **is this a question?** Yes, '.
                'the answer is that some parents do wonder. **Others?** They do not.';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('Text', $out->blocks[0]->type);
    }

    // ─── stat_table fold ────────────────────────────────────────────────

    #[Test]
    public function record_list_folds_to_table_larry_wruck_shape(): void
    {
        // cjfl Larry Wruck Defensive Player of the Year — 19 rows of
        // "YYYY - Name - Team - Position" that block-fill emits as a
        // Text block with a bulleted markdown body. The mapper's
        // stat_table fold turns it into a Table so record content
        // renders tabular, not as a wall of prose.
        $body = "- 2025 - Jaylin Burnett - St. Clair Saints - DL\n".
                "- 2024 - Stephen Smith - Regina Thunder - LB\n".
                "- 2023 - Stephen Smith - Regina Thunder - LB\n".
                "- 2022 - Konner Johnson - Saskatoon Hilltops - LB\n".
                "- 2021 - Austin Daisy - Calgary Colts - LB\n".
                '- 2019 - Jaydn Pingue - Saskatoon Hilltops - LB';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );

        $this->assertCount(1, $out->blocks);
        $this->assertSame('Table', $out->blocks[0]->type);
        $rows = $out->blocks[0]->props['rows'];
        $this->assertCount(6, $rows);
        $this->assertCount(4, $rows[0]['cells']);
        // Cell content is a Text block inside a slot array.
        $firstCellContent = $rows[0]['cells'][0]['content'];
        $this->assertSame('Text', $firstCellContent[0]['type']);
        $this->assertSame('2025', $firstCellContent[0]['props']['body']);
        $this->assertSame('Jaylin Burnett', $rows[0]['cells'][1]['content'][0]['props']['body']);
        $this->assertFalse($out->blocks[0]->props['hasHeaderRow']);

        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('text_list_folded_to_table', $codes);
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function record_list_folds_with_em_dash_separator(): void
    {
        // cjfl Peter Dalla Riva uses em-dashes (Unicode U+2014). Both
        // ` - ` and ` — ` must fold. Order matters — em-dash is checked
        // first because a body may contain both (compound name with
        // hyphen; em-dash between columns).
        $body = "- 2025 — Matt Guenette — St. Clair Saints — QB\n".
                "- 2024 — Elelyon Noa — Okanagan Sun — RB\n".
                "- 2023 — Te Jessie — Westshore Rebels — QB\n".
                "- 2022 — Te Jessie — Westshore Rebels — QB\n".
                '- 2021 — Malcolm Miller — Kamloops Broncos — RB';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );

        $this->assertSame('Table', $out->blocks[0]->type);
        $this->assertCount(5, $out->blocks[0]->props['rows']);
        $this->assertCount(4, $out->blocks[0]->props['rows'][0]['cells']);
        $this->assertValidates($out->blocks);
    }

    // ─── stat_table NEGATIVE tests (adjacent patterns must NOT fold) ────

    #[Test]
    public function short_bullet_list_below_min_rows_stays_as_text(): void
    {
        // Under 5 items — normal prose bullet list, not a record list.
        $body = "- First item - detail\n".
                "- Second item - detail\n".
                '- Third item - detail';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertCount(1, $out->blocks);
        $this->assertSame('Text', $out->blocks[0]->type);
    }

    #[Test]
    public function bullet_list_without_column_separator_stays_as_text(): void
    {
        // tbird Parents Portal "What We Ask of Our Families" shape —
        // 6 numbered bold-heading items with no consistent separator.
        // Must NOT fold to a Table (or the sanitiser would eat the
        // Q-shaped prose bodies entirely).
        $body = "1. **Attend the Parent Meeting** Get connected early!\n".
                "2. **Be Prompt and Prepared** Please ensure your child arrives on time.\n".
                "3. **Be a Positive Presence** Cheer with encouragement!\n".
                "4. **Lend a Helping Hand** We count on our parent volunteers.\n".
                "5. **Keep the Communication Flowing** Stay in touch with your coach.\n".
                '6. **Support Growth and Sportsmanship** Celebrate effort, not just outcomes.';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertCount(1, $out->blocks);
        $this->assertSame('Text', $out->blocks[0]->type);
    }

    #[Test]
    public function prose_paragraph_stays_as_text(): void
    {
        // No list markers at all — pure prose, must stay Text.
        $body = 'Located in West Chester and Liberty Township, Ohio, the Lakota Thunderbird Youth Basketball Organization is a premier basketball program in the Northern suburbs of Cincinnati. Since its establishment in 1987, the organization has grown significantly and remains a cornerstone of the local sports community.';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertCount(1, $out->blocks);
        $this->assertSame('Text', $out->blocks[0]->type);
    }

    #[Test]
    public function inconsistent_column_counts_stays_as_text(): void
    {
        // If items have different column counts, the pattern isn't
        // tabular. Adjacent shape: mixed content notes with some
        // dashes but not the same shape per row.
        $body = "- 2025 - Jaylin Burnett - St. Clair Saints - DL\n".
                "- 2024 - Stephen Smith\n". // only 2 cols
                "- 2023 - Stephen Smith - Regina Thunder - LB\n".
                "- 2022 - Konner Johnson - Saskatoon Hilltops - LB - MVP\n". // 5 cols
                "- 2021 - Austin Daisy - Calgary Colts - LB\n".
                '- 2019 - Jaydn Pingue - Saskatoon Hilltops - LB';

        $out = $this->mapper->mapContent(
            [['type' => 'Text', 'props' => ['body' => $body]]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('Text', $out->blocks[0]->type);
    }

    // ─── helpers ────────────────────────────────────────────────────────

    private function assetContext(): AssetContext
    {
        return new AssetContext(new DataCollection(AssetRef::class, []));
    }

    /**
     * @param  array<int, Block>  $blocks
     */
    private function assertValidates(array $blocks): void
    {
        foreach ($blocks as $i => $block) {
            $issues = $this->validator->validateBlock($block, "content[{$i}]");
            $errors = array_values(array_filter($issues, fn ($x) => $x->severity === 'error'));
            $this->assertSame([], $errors, "block {$i} ({$block->type}) must validate: ".json_encode(array_map(fn ($e) => $e->message, $errors), JSON_PRETTY_PRINT));
        }
    }
}
