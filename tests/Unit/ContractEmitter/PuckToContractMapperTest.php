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
    public function columns_of_sponsor_cards_do_no_t_fold_to_team_members(): void
    {
        // Sponsor cards: title=business name, body=CTA, href set even
        // when placeholder "#". Must NOT fold to TeamMembers — falls
        // back to columns_flattened.
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
        // NOT folded to TeamMembers.
        $types = array_map(fn ($b) => $b->type, $out->blocks);
        $this->assertNotContains('TeamMembers', $types, 'sponsor Cards must NOT be misidentified as a people directory');
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        // Fell back to flatten path.
        $this->assertContains('columns_flattened', $codes);
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

    // ─── Columns → flatten + diagnostic ─────────────────────────────────

    #[Test]
    public function old_columns_flatten_to_top_level_stack_plus_diagnostic(): void
    {
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

        $this->assertCount(2, $out->blocks);
        $this->assertSame('Text', $out->blocks[0]->type);
        $this->assertSame('Text', $out->blocks[1]->type);
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('columns_flattened', $codes);
        $this->assertValidates($out->blocks);
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
