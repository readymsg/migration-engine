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
