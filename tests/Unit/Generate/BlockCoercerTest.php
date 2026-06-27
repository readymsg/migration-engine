<?php

declare(strict_types=1);

namespace Tests\Unit\Generate;

use App\Data\AssemblyCoercion;
use App\Services\Generate\BlockCoercer;
use App\Services\Generate\CoercerIssue;
use App\Services\Schema\DefaultPuckComponentSchema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Coercer test: the boundary between SILENT NORMALIZATIONS (value-
// preserving — no issue recorded) and RECORDED COERCIONS (Substitution
// when block survives, Drop when block is removed). The cardinal test
// is: does the coercion change WHAT the source said (record it) or
// just HOW it's encoded (silent)?
final class BlockCoercerTest extends TestCase
{
    private function coercer(): BlockCoercer
    {
        return new BlockCoercer(new DefaultPuckComponentSchema);
    }

    /**
     * @param  array<int, CoercerIssue>  $issues
     * @return array<int, CoercerIssue>
     */
    private function ofCoercion(array $issues, AssemblyCoercion $coercion): array
    {
        return array_values(array_filter($issues, static fn (CoercerIssue $i): bool => $i->coercion === $coercion));
    }

    // ─── normalizations (silent — NO issue recorded) ──────────────────

    #[Test]
    public function whitespace_trim_on_text_is_silent(): void
    {
        $result = $this->coercer()->coerce('Heading', [
            'text' => '  Welcome  ',
            'level' => 'h1',
        ]);

        $this->assertFalse($result->dropped);
        $this->assertSame('Welcome', $result->coerced_props['text']);
        $this->assertSame([], $result->issues, 'whitespace trim is value-preserving — must not record');
    }

    #[Test]
    public function h1_h6_case_fix_is_silent(): void
    {
        $result = $this->coercer()->coerce('Heading', [
            'text' => 'Hi',
            'level' => 'H1',
        ]);

        $this->assertFalse($result->dropped);
        $this->assertSame('h1', $result->coerced_props['level']);
        $this->assertSame([], $result->issues, 'case fix to known option is value-preserving — must not record');
    }

    #[Test]
    public function dropping_unknown_prop_key_is_silent(): void
    {
        $result = $this->coercer()->coerce('Hero', [
            'heading' => 'Hi',
            'extra_garbage' => 'should be dropped',
        ]);

        $this->assertFalse($result->dropped);
        $this->assertSame(['heading' => 'Hi'], $result->coerced_props);
        $this->assertSame([], $result->issues, 'dropping a non-schema prop is value-preserving — must not record');
    }

    #[Test]
    public function dropping_missing_optional_field_is_silent(): void
    {
        // Hero.subheading is optional. Missing → silently absent.
        $result = $this->coercer()->coerce('Hero', [
            'heading' => 'Hi',
        ]);

        $this->assertFalse($result->dropped);
        $this->assertArrayHasKey('heading', $result->coerced_props);
        $this->assertArrayNotHasKey('subheading', $result->coerced_props);
        $this->assertSame([], $result->issues);
    }

    // ─── substitutions (block survives, issue recorded) ───────────────

    #[Test]
    public function empty_href_on_button_substitutes_hash_and_records(): void
    {
        $result = $this->coercer()->coerce('ButtonGroup', [
            'buttons' => [
                ['label' => 'Register', 'href' => ''],
            ],
        ]);

        $this->assertFalse($result->dropped);
        $this->assertSame('#', $result->coerced_props['buttons'][0]['href']);

        $subs = $this->ofCoercion($result->issues, AssemblyCoercion::Substitution);
        $this->assertCount(1, $subs);
        $this->assertSame('props.buttons[0].href', $subs[0]->path);
        $this->assertStringContainsString("'#'", $subs[0]->reason);
    }

    #[Test]
    public function select_value_not_in_options_substitutes_documented_default_and_records(): void
    {
        $result = $this->coercer()->coerce('Heading', [
            'text' => 'Hi',
            'level' => 'h7',
        ]);

        $this->assertFalse($result->dropped);
        // First option in the schema is 'h1'.
        $this->assertSame('h1', $result->coerced_props['level']);

        $subs = $this->ofCoercion($result->issues, AssemblyCoercion::Substitution);
        $this->assertCount(1, $subs);
        $this->assertSame('props.level', $subs[0]->path);
        $this->assertStringContainsString("'h7'", $subs[0]->reason);
        $this->assertStringContainsString("'h1'", $subs[0]->reason);
    }

    #[Test]
    public function missing_heading_level_substitutes_h2_and_records(): void
    {
        $result = $this->coercer()->coerce('Heading', [
            'text' => 'Welcome',
        ]);

        $this->assertFalse($result->dropped, 'heading with real text should survive — level is a rendering default');
        $this->assertSame('h2', $result->coerced_props['level']);

        $subs = $this->ofCoercion($result->issues, AssemblyCoercion::Substitution);
        $this->assertCount(1, $subs);
        $this->assertSame('props.level', $subs[0]->path);
    }

    // ─── drops (block removed, issue recorded) ────────────────────────

    #[Test]
    public function missing_required_card_title_drops_block_and_records(): void
    {
        $result = $this->coercer()->coerce('Card', [
            'body' => 'No title here',
        ]);

        $this->assertTrue($result->dropped);
        $this->assertNull($result->coerced_props);

        $drops = $this->ofCoercion($result->issues, AssemblyCoercion::Drop);
        $this->assertCount(1, $drops);
        $this->assertSame('props.title', $drops[0]->path);
        $this->assertStringContainsString("required 'title'", $drops[0]->reason);
    }

    #[Test]
    public function missing_required_hero_heading_drops_block(): void
    {
        $result = $this->coercer()->coerce('Hero', [
            'subheading' => 'Just a subheading',
        ]);

        $this->assertTrue($result->dropped);
        $this->assertNull($result->coerced_props);

        $drops = $this->ofCoercion($result->issues, AssemblyCoercion::Drop);
        $this->assertCount(1, $drops);
        $this->assertSame('props.heading', $drops[0]->path);
    }

    #[Test]
    public function unknown_component_type_drops_block_and_records(): void
    {
        $result = $this->coercer()->coerce('Sidebar', [
            'whatever' => 'x',
        ]);

        $this->assertTrue($result->dropped);
        $this->assertNull($result->coerced_props);

        $drops = $this->ofCoercion($result->issues, AssemblyCoercion::Drop);
        $this->assertCount(1, $drops);
        $this->assertSame('Sidebar', $drops[0]->component_type);
        $this->assertStringContainsString("unknown component_type 'Sidebar'", $drops[0]->reason);
    }

    #[Test]
    public function button_with_missing_label_is_dropped_but_other_buttons_survive(): void
    {
        // Per the rule: missing-required-prop → drop, with carve-outs
        // ONLY for href ('#') and select default. A missing label is
        // not substituted ('Learn more' would masquerade as real
        // content). The bad button is dropped; siblings live.
        $result = $this->coercer()->coerce('ButtonGroup', [
            'buttons' => [
                ['label' => 'Register', 'href' => '/register'],
                ['href' => '/missing-label'],
                ['label' => 'Contact', 'href' => '/contact'],
            ],
        ]);

        $this->assertFalse($result->dropped);
        $this->assertCount(2, $result->coerced_props['buttons']);
        $this->assertSame('Register', $result->coerced_props['buttons'][0]['label']);
        $this->assertSame('Contact', $result->coerced_props['buttons'][1]['label']);

        $drops = $this->ofCoercion($result->issues, AssemblyCoercion::Drop);
        // The button item was dropped; the parent group survived.
        $this->assertNotEmpty($drops);
        $foundDropped = false;
        foreach ($drops as $d) {
            if (str_starts_with($d->path ?? '', 'props.buttons[1]')) {
                $foundDropped = true;
                break;
            }
        }
        $this->assertTrue($foundDropped, 'the missing-label button item must be recorded as dropped');
    }

    #[Test]
    public function button_group_with_all_buttons_dropped_drops_the_whole_block(): void
    {
        // buttons is required AND non-empty after coercion = required.
        // Empty → parent dropped.
        $result = $this->coercer()->coerce('ButtonGroup', [
            'buttons' => [
                ['href' => '/a'], // no label → button dropped
            ],
        ]);

        $this->assertTrue($result->dropped);
        $this->assertNull($result->coerced_props);
    }

    // ─── nested Columns.children: recursive coercion ──────────────────

    #[Test]
    public function clean_columns_with_card_and_image_children_pass_through(): void
    {
        $result = $this->coercer()->coerce('Columns', [
            'columns' => [
                [
                    'width' => '1/2',
                    'children' => [
                        ['component_type' => 'Card', 'props' => ['title' => 'P1', 'body' => 'a']],
                        ['component_type' => 'Image', 'props' => ['src' => 's3://bucket/x.jpg', 'alt' => 'x']],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($result->dropped);
        $this->assertSame([], $result->issues);
        $children = $result->coerced_props['columns'][0]['children'];
        $this->assertCount(2, $children);
        // Coerced children emit Puck-shaped {type, props}.
        $this->assertSame('Card', $children[0]['type']);
        $this->assertSame('Image', $children[1]['type']);
    }

    #[Test]
    public function nested_card_missing_required_title_is_dropped_parent_columns_survives(): void
    {
        $result = $this->coercer()->coerce('Columns', [
            'columns' => [
                [
                    'width' => '1/2',
                    'children' => [
                        ['component_type' => 'Card', 'props' => ['body' => 'No title']],
                        ['component_type' => 'Card', 'props' => ['title' => 'Real', 'body' => 'real']],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($result->dropped, 'parent Columns must survive even when one child drops');
        $children = $result->coerced_props['columns'][0]['children'];
        $this->assertCount(1, $children);
        $this->assertSame('Real', $children[0]['props']['title']);

        // The dropped child should have a Drop issue with a nested path.
        $drops = $this->ofCoercion($result->issues, AssemblyCoercion::Drop);
        $this->assertNotEmpty($drops);
        $found = false;
        foreach ($drops as $d) {
            if (str_starts_with($d->path ?? '', 'props.columns[0].children[0]')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'dropped child path must be reported with the nested path');
    }

    #[Test]
    public function nested_unknown_component_type_is_dropped_at_nested_path(): void
    {
        $result = $this->coercer()->coerce('Columns', [
            'columns' => [
                [
                    'width' => '1/2',
                    'children' => [
                        ['component_type' => 'Carousel', 'props' => []],
                        ['component_type' => 'Card', 'props' => ['title' => 'Ok']],
                    ],
                ],
            ],
        ]);

        $this->assertFalse($result->dropped);
        $children = $result->coerced_props['columns'][0]['children'];
        $this->assertCount(1, $children);

        $drops = $this->ofCoercion($result->issues, AssemblyCoercion::Drop);
        $foundUnknown = false;
        foreach ($drops as $d) {
            if (str_contains($d->reason, 'Carousel')) {
                $foundUnknown = true;
                break;
            }
        }
        $this->assertTrue($foundUnknown);
    }

    // ─── type coercions ────────────────────────────────────────────────

    #[Test]
    public function int_into_text_field_silently_stringifies(): void
    {
        $result = $this->coercer()->coerce('Card', [
            'title' => 1987,
            'body' => 'founded',
        ]);

        $this->assertFalse($result->dropped);
        $this->assertSame('1987', $result->coerced_props['title']);
        $this->assertSame([], $result->issues, 'int → text is value-preserving — must not record');
    }

    #[Test]
    public function array_into_required_text_field_drops_the_block(): void
    {
        // No safe cast — title='real text' can't be reconstituted from
        // an array. Drop.
        $result = $this->coercer()->coerce('Card', [
            'title' => ['some', 'array'],
            'body' => 'body',
        ]);

        $this->assertTrue($result->dropped);
        $drops = $this->ofCoercion($result->issues, AssemblyCoercion::Drop);
        $this->assertNotEmpty($drops);
    }
}
