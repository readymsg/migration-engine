<?php

declare(strict_types=1);

namespace Tests\Unit\ContractEmitter;

use App\Data\SiteImport\Block;
use App\Services\ContractEmitter\ContractSchema;
use App\Services\ContractEmitter\ContractSchemaValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Slice 16 pins:
//
// 16a — stockMediaDefaults must never be shipped in a prop. Contract
//        hardRules #9. The values are environment-resolved
//        placeholders; echoing one puts a stock hockey photo on the
//        club's site.
//
// 16b — knownDiscrepancies: three props declared string, code ships
//        number/null. Our validator accepts both types via the
//        `string_or_number` / nullable-string normalized shapes so
//        engineering's reference payload validates clean.
final class StockMediaAndDiscrepancyTest extends TestCase
{
    private ContractSchema $schema;

    private ContractSchemaValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = ContractSchema::load();
        $this->validator = new ContractSchemaValidator($this->schema);
    }

    // ─── 16a: stock-media rule ─────────────────────────────────────────

    #[Test]
    public function hero_image_url_equal_to_stock_default_is_flagged(): void
    {
        $block = new Block(type: 'Hero', props: [
            'id' => 'test',
            'heading' => 'Welcome',
            'imageUrl' => '/photos/football-banner.jpg', // the Hero stock default
        ]);
        $codes = array_map(fn ($i) => $i->code, $this->validator->validateBlock($block));
        $this->assertContains('stock_media_default_copied', $codes);
    }

    #[Test]
    public function gallery_image_src_matching_a_stock_default_inside_array_is_flagged(): void
    {
        // Gallery.images[] carries a nested src. The walker MUST
        // descend into array-of-object values.
        $block = new Block(type: 'Gallery', props: [
            'id' => 'test',
            'images' => [
                ['src' => 'https://cdn.example.com/team.jpg', 'alt' => ''],
                ['src' => '/photos/baseball-action.jpg', 'alt' => ''], // stock default
                ['src' => 'https://cdn.example.com/coach.jpg', 'alt' => ''],
            ],
        ]);
        $codes = array_map(fn ($i) => $i->code, $this->validator->validateBlock($block));
        $this->assertContains('stock_media_default_copied', $codes);
    }

    #[Test]
    public function image_placeholder_url_is_flagged(): void
    {
        // Image ships an https:// placehold.co URL as its stock default.
        $block = new Block(type: 'Image', props: [
            'id' => 'test',
            'src' => 'https://placehold.co/1200x600/cccccc/333333?text=Image',
        ]);
        $codes = array_map(fn ($i) => $i->code, $this->validator->validateBlock($block));
        $this->assertContains('stock_media_default_copied', $codes);
    }

    #[Test]
    public function real_org_image_url_is_not_flagged(): void
    {
        $block = new Block(type: 'Hero', props: [
            'id' => 'test',
            'heading' => 'Welcome',
            'imageUrl' => 'tl-asset:banner-x',
        ]);
        $issues = $this->validator->validateBlock($block);
        $stock = array_filter($issues, fn ($i) => $i->code === 'stock_media_default_copied');
        $this->assertEmpty($stock);
    }

    #[Test]
    public function block_without_stock_defaults_is_not_gated(): void
    {
        // Text has no stockMediaDefaults; a stock-shaped path would
        // slip through as a plain string.
        $block = new Block(type: 'Text', props: [
            'id' => 'test',
            'body' => '<p>Hello</p>',
            'as' => 'p',
        ]);
        $issues = $this->validator->validateBlock($block);
        $stock = array_filter($issues, fn ($i) => $i->code === 'stock_media_default_copied');
        $this->assertEmpty($stock);
    }

    // ─── 16b: knownDiscrepancies ───────────────────────────────────────

    #[Test]
    public function statistics_items_value_accepts_number_despite_declared_string(): void
    {
        // Contract's knownDiscrepancy #2: Statistics.items[].value
        // declared string, block ships number. Our normalizer maps
        // it to `type: 'string_or_number'`; validator accepts both.
        $block = new Block(type: 'Statistics', props: [
            'id' => 'test',
            'items' => [
                ['rank' => 1, 'player' => 'A. Smith', 'value' => 28, 'secondary' => 14],
                ['rank' => 2, 'player' => 'B. Jones', 'value' => '25', 'secondary' => '12'], // strings also OK
            ],
        ]);
        $issues = $this->validator->validateBlock($block);
        $wrongType = array_filter($issues, fn ($i) => $i->code === 'wrong_type');
        $this->assertEmpty($wrongType, 'Statistics.items[].value must accept string and number');
    }

    #[Test]
    public function locations_items_description_accepts_null_despite_declared_string(): void
    {
        // Contract's knownDiscrepancy #1: Locations.items[].description
        // declared string, block ships null. Normalized to
        // `{type: 'string', nullable: true}`.
        $block = new Block(type: 'Locations', props: [
            'id' => 'test',
            'items' => [
                [
                    'name' => 'Arena',
                    'address' => '123 St',
                    'description' => null,
                ],
            ],
        ]);
        $issues = $this->validator->validateBlock($block);
        $wrongType = array_filter($issues, fn ($i) => $i->code === 'wrong_type');
        $this->assertEmpty($wrongType);
    }

    #[Test]
    public function known_discrepancies_lists_the_three_declared_paths_and_no_others(): void
    {
        // Report gate: the file lists 3 discrepancies. Our audit
        // (Slice 16 investigation) found no others where declared !=
        // shipped type. If a NEW one appears here, tell engineering.
        $expected = [
            'Locations.items[].description',
            'Statistics.items[].secondary',
            'Statistics.items[].value',
        ];
        $actual = array_keys($this->schema->knownDiscrepancies());
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }
}
