<?php

declare(strict_types=1);

namespace Tests\Unit\ContractEmitter;

use App\Data\SiteImport\Block;
use App\Data\SiteImport\ValidationIssue;
use App\Services\ContractEmitter\ContractSchema;
use App\Services\ContractEmitter\ContractSchemaValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Pins the per-block validator against contract Part VI's self-check
// rules 1-6 (envelope-level checks 7-11 land in Slice 9).
//
// The five thin-slice blocks are tested positively (correct props
// produce zero errors) and negatively (each rule fires on the
// specific violation shape it was written for).
final class ContractSchemaValidatorTest extends TestCase
{
    private ContractSchemaValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ContractSchemaValidator(ContractSchema::load());
    }

    // ─── happy path — each of the five blocks passes with reasonable props ─

    #[Test]
    public function text_block_with_valid_props_has_no_errors(): void
    {
        $issues = $this->validator->validateBlock(new Block(
            type: 'Text',
            props: [
                'id' => 'text-abc123',
                'body' => '<p>Welcome to the community.</p>',
                'as' => 'p',
                'align' => 'left',
            ],
        ));
        $this->assertSame([], $this->errors($issues), 'valid Text block must not produce errors');
    }

    #[Test]
    public function hero_block_with_valid_props_has_no_errors(): void
    {
        $issues = $this->validator->validateBlock(new Block(
            type: 'Hero',
            props: [
                'id' => 'hero-abc123',
                'layout' => 'overlay',
                'imageUrl' => 'tl-asset:home-hero',
                'heading' => 'Welcome',
                'subheading' => 'Serving the community since 1974',
                'primaryButton' => ['label' => 'Register', 'href' => '/register'],
            ],
        ));
        $this->assertSame([], $this->errors($issues));
    }

    #[Test]
    public function gallery_block_with_valid_props_has_no_errors(): void
    {
        $issues = $this->validator->validateBlock(new Block(
            type: 'Gallery',
            props: [
                'id' => 'gallery-abc123',
                'images' => [
                    ['src' => 'tl-asset:pic1', 'alt' => 'Team photo', 'caption' => ''],
                    ['src' => 'tl-asset:pic2', 'alt' => '', 'caption' => 'Court'],
                ],
                'columns' => 3,
                'lightbox' => true,
                'showCaptions' => false,
            ],
        ));
        $this->assertSame([], $this->errors($issues), 'Gallery must accept images[] with {src,alt,caption} and numeric columns enum');
    }

    #[Test]
    public function image_block_with_valid_props_has_no_errors(): void
    {
        $issues = $this->validator->validateBlock(new Block(
            type: 'Image',
            props: [
                'id' => 'image-abc123',
                'src' => 'tl-asset:hero',
                'alt' => 'The court',
                'aspectRatio' => '16/9',
            ],
        ));
        $this->assertSame([], $this->errors($issues));
    }

    #[Test]
    public function button_block_with_valid_props_has_no_errors(): void
    {
        $issues = $this->validator->validateBlock(new Block(
            type: 'Button',
            props: [
                'id' => 'button-abc123',
                'label' => 'Register',
                'href' => '/register',
                'variant' => 'solid',
                'size' => '3',
            ],
        ));
        $this->assertSame([], $this->errors($issues), 'Button.size accepts STRING "3", not number 3');
    }

    // ─── rule 1: unknown block type ────────────────────────────────────────

    #[Test]
    public function unknown_block_type_is_error(): void
    {
        $issues = $this->validator->validateBlock(new Block(
            type: 'Card', // our old schema had this; contract doesn't
            props: ['id' => 'card-abc123'],
        ));
        $errors = $this->errors($issues);
        $this->assertCount(1, $errors);
        $this->assertSame('unknown_block_type', $errors[0]->code);
        $this->assertStringContainsString('grey', $errors[0]->message, 'error message must warn about the grey placeholder consequence');
    }

    // ─── rule 2: chrome blocks refused ─────────────────────────────────────

    #[Test]
    public function chrome_block_is_refused(): void
    {
        foreach (['NavMenu', 'SiteNotice', 'FooterColumns', 'FooterLogo', 'FooterSocial', 'IntakeForm'] as $chrome) {
            $issues = $this->validator->validateBlock(new Block(
                type: $chrome,
                props: ['id' => strtolower($chrome).'-abc123'],
            ));
            $codes = array_map(fn ($i) => $i->code, $this->errors($issues));
            $this->assertContains(
                'chrome_block_emitted',
                $codes,
                "{$chrome} must be refused by the validator",
            );
        }
    }

    // ─── rule 3: unknown prop key (typo) ───────────────────────────────────

    #[Test]
    public function unknown_prop_key_is_error_our_old_hero_shape(): void
    {
        // The exact shape our old preview emits — Hero.background_image
        // instead of the contract's Hero.imageUrl. Must be flagged loud.
        $issues = $this->validator->validateBlock(new Block(
            type: 'Hero',
            props: [
                'id' => 'hero-abc123',
                'background_image' => 'tl-asset:hero',
                'heading' => 'Welcome',
            ],
        ));
        $errors = $this->errors($issues);
        $this->assertCount(1, $errors);
        $this->assertSame('unknown_prop_key', $errors[0]->code);
        $this->assertStringContainsString('background_image', $errors[0]->message);
        $this->assertStringContainsString('storage contract', $errors[0]->message);
    }

    #[Test]
    public function gallery_items_instead_of_images_is_flagged(): void
    {
        // Our old Gallery emits items[]; contract expects images[].
        $issues = $this->validator->validateBlock(new Block(
            type: 'Gallery',
            props: [
                'id' => 'gallery-abc123',
                'items' => [['src' => 'x']],
            ],
        ));
        $codes = array_map(fn ($i) => $i->code, $this->errors($issues));
        $this->assertContains('unknown_prop_key', $codes);
    }

    // ─── rule 4: server-owned props refused ────────────────────────────────

    #[Test]
    public function resolved_prop_is_refused(): void
    {
        $issues = $this->validator->validateBlock(new Block(
            type: 'Hero',
            props: [
                'id' => 'hero-abc123',
                'resolvedItems' => [1, 2, 3],
            ],
        ));
        $codes = array_map(fn ($i) => $i->code, $this->errors($issues));
        $this->assertContains('server_owned_prop_authored', $codes);
    }

    #[Test]
    public function form_uuid_is_refused(): void
    {
        $issues = $this->validator->validateBlock(new Block(
            type: 'Hero', // even on a block that doesn't normally have it
            props: [
                'id' => 'hero-abc123',
                'formUuid' => 'some-uuid',
            ],
        ));
        $codes = array_map(fn ($i) => $i->code, $this->errors($issues));
        $this->assertContains('server_owned_prop_authored', $codes);
    }

    // ─── rule 5: enum values (with type-strict comparison) ─────────────────

    #[Test]
    public function enum_string_vs_number_mismatch_is_error(): void
    {
        // Button.size is enum ["1","2","3","4"] as STRINGS. Sending
        // the number 3 must fail — a JSON-type mismatch is a real
        // bug (see contract Part III "Reading the type column").
        $issues = $this->validator->validateBlock(new Block(
            type: 'Button',
            props: [
                'id' => 'button-abc123',
                'size' => 3, // number instead of "3"
            ],
        ));
        $codes = array_map(fn ($i) => $i->code, $this->errors($issues));
        $this->assertContains('enum_value_invalid', $codes);
    }

    #[Test]
    public function gallery_columns_string_vs_number_mismatch_is_error(): void
    {
        // Gallery.columns is enum [2,3,4,5] as NUMBERS. Sending "3"
        // (string) must fail — inverse of the Button.size case.
        $issues = $this->validator->validateBlock(new Block(
            type: 'Gallery',
            props: [
                'id' => 'gallery-abc123',
                'columns' => '3', // string instead of 3
            ],
        ));
        $codes = array_map(fn ($i) => $i->code, $this->errors($issues));
        $this->assertContains('enum_value_invalid', $codes);
    }

    #[Test]
    public function hero_layout_outside_enum_is_error(): void
    {
        $issues = $this->validator->validateBlock(new Block(
            type: 'Hero',
            props: [
                'id' => 'hero-abc123',
                'layout' => 'carousel', // invented value
            ],
        ));
        $codes = array_map(fn ($i) => $i->code, $this->errors($issues));
        $this->assertContains('enum_value_invalid', $codes);
    }

    // ─── rule 6: numeric ranges are WARNINGS not errors ────────────────────

    #[Test]
    public function number_outside_range_is_warning_not_error(): void
    {
        // Contract Part III: "Ranges are editor slider bounds, not
        // validation — they are not enforced on save — but stay
        // inside them." So a saved value outside range is a warning.
        $issues = $this->validator->validateBlock(new Block(
            type: 'Text',
            props: [
                'id' => 'text-abc123',
                'fontSize' => 500, // way above the 120 slider max
            ],
        ));
        $errors = $this->errors($issues);
        $warnings = array_filter($issues, fn ($i) => $i->severity === 'warning');
        $this->assertSame([], $errors, 'range violations must NOT be errors');
        $this->assertCount(1, $warnings);
        $warning = array_values($warnings)[0];
        $this->assertSame('number_above_slider_range', $warning->code);
    }

    #[Test]
    public function number_wrong_type_is_error(): void
    {
        // fontSize as a string is a real type error, not a range warning.
        $issues = $this->validator->validateBlock(new Block(
            type: 'Text',
            props: [
                'id' => 'text-abc123',
                'fontSize' => '16',
            ],
        ));
        $codes = array_map(fn ($i) => $i->code, $this->errors($issues));
        $this->assertContains('wrong_type', $codes);
    }

    // ─── rule 7: props.id required ─────────────────────────────────────────

    #[Test]
    public function missing_id_is_error(): void
    {
        $issues = $this->validator->validateBlock(new Block(
            type: 'Text',
            props: ['body' => '<p>No id</p>'],
        ));
        $codes = array_map(fn ($i) => $i->code, $this->errors($issues));
        $this->assertContains('missing_block_id', $codes);
    }

    #[Test]
    public function empty_string_id_is_error(): void
    {
        $issues = $this->validator->validateBlock(new Block(
            type: 'Text',
            props: ['id' => ''],
        ));
        $codes = array_map(fn ($i) => $i->code, $this->errors($issues));
        $this->assertContains('missing_block_id', $codes);
    }

    // ─── nested object + array validation ─────────────────────────────────

    #[Test]
    public function hero_primary_button_object_shape_is_validated(): void
    {
        // primaryButton is an object with keys {label, href}. Unknown
        // key must fire; wrong-type value must fire.
        $issues = $this->validator->validateBlock(new Block(
            type: 'Hero',
            props: [
                'id' => 'hero-abc123',
                'primaryButton' => [
                    'label' => 'Register',
                    'href' => '/register',
                    'target' => '_blank', // not a declared key
                ],
            ],
        ));
        $codes = array_map(fn ($i) => $i->code, $this->errors($issues));
        $this->assertContains('unknown_object_key', $codes);
    }

    #[Test]
    public function gallery_images_array_items_validated_per_element(): void
    {
        // Each images[] entry has {src, alt, caption}. An extra key on
        // ONE entry must fire (per-index path).
        $issues = $this->validator->validateBlock(new Block(
            type: 'Gallery',
            props: [
                'id' => 'gallery-abc123',
                'images' => [
                    ['src' => 'tl-asset:pic1'],
                    ['src' => 'tl-asset:pic2', 'wrongKey' => 'x'],
                ],
            ],
        ));
        $errors = $this->errors($issues);
        $this->assertCount(1, $errors);
        $this->assertSame('unknown_object_key', $errors[0]->code);
        $this->assertNotNull($errors[0]->path);
        $this->assertStringContainsString('images[1]', (string) $errors[0]->path, 'path must locate the offending array index');
    }

    // ─── path prefixing for envelope-level composition ─────────────────────

    #[Test]
    public function path_prefix_is_prepended_to_issues(): void
    {
        $issues = $this->validator->validateBlock(
            new Block(type: 'Hero', props: ['id' => 'hero-abc123', 'layout' => 'wrong']),
            pathPrefix: 'pages[0].data.content[3]',
        );
        $errors = $this->errors($issues);
        $this->assertCount(1, $errors);
        $this->assertSame('pages[0].data.content[3].props.layout', $errors[0]->path);
    }

    /**
     * @param  array<int, ValidationIssue>  $issues
     * @return array<int, ValidationIssue>
     */
    private function errors(array $issues): array
    {
        return array_values(array_filter($issues, fn ($i) => $i->severity === 'error'));
    }
}
