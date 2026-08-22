<?php

declare(strict_types=1);

namespace Tests\Unit\ContractEmitter;

use App\Data\SiteImport\Block;
use App\Services\ContractEmitter\ContractSchema;
use App\Services\ContractEmitter\ContractSchemaValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Slice 15 pin: every x-teamlinkt vocabulary that Slice 14 exposed
// on ContractSchema is CONSUMED by the pipeline. A regression that
// silently re-hardcodes one of these would let the code drift out
// of sync with the file, defeating the whole point of importing
// the real schema.
final class XTeamlinktVocabulariesConsumedTest extends TestCase
{
    private ContractSchema $schema;

    private ContractSchemaValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = ContractSchema::load();
        $this->validator = new ContractSchemaValidator($this->schema);
    }

    // ─── neverEmitBlocks (6) ────────────────────────────────────────────

    #[Test]
    public function never_emit_blocks_list_matches_file_and_all_are_chrome_marked(): void
    {
        $expected = ['FooterColumns', 'FooterLogo', 'FooterSocial', 'IntakeForm', 'NavMenu', 'SiteNotice'];
        $actual = array_keys($this->schema->neverEmitBlocks());
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
        foreach ($expected as $type) {
            $this->assertTrue($this->schema->isChromeBlock($type), "{$type} must be chromeOnly");
        }
    }

    #[Test]
    public function every_never_emit_block_produces_chrome_block_emitted_error(): void
    {
        foreach ($this->schema->neverEmitBlocks() as $type => $_) {
            $block = new Block(type: $type, props: ['id' => 'test-id']);
            $issues = $this->validator->validateBlock($block);
            $codes = array_map(fn ($i) => $i->code, $issues);
            $this->assertContains('chrome_block_emitted', $codes, "{$type} must emit chrome_block_emitted");
        }
    }

    // ─── orgTypeGating (9 restricted blocks) ────────────────────────────

    #[Test]
    public function org_type_gating_matches_file(): void
    {
        $gating = $this->schema->orgTypeGating();
        // Contract Part II's 9 restricted blocks.
        $this->assertArrayHasKey('Schedule', $gating);
        $this->assertArrayHasKey('Scores', $gating);
        $this->assertArrayHasKey('ScoresSchedule', $gating);
        $this->assertArrayHasKey('Standings', $gating);
        $this->assertArrayHasKey('Statistics', $gating);
        $this->assertArrayHasKey('Suspensions', $gating);
        $this->assertArrayHasKey('TeamRoster', $gating);
        $this->assertArrayHasKey('Teams', $gating);
        $this->assertArrayHasKey('EventMarquee', $gating);
    }

    #[Test]
    public function schedule_is_gated_by_the_file_not_a_hardcode(): void
    {
        // Consumed via ContractSchema::blockAllowsOrgType which reads
        // orgTypesFor which reads from x-teamlinkt.orgTypeGating.
        $this->assertFalse($this->schema->blockAllowsOrgType('Schedule', 'club'));
        $this->assertFalse($this->schema->blockAllowsOrgType('Schedule', 'civic'));
        $this->assertTrue($this->schema->blockAllowsOrgType('Schedule', 'league'));
        $this->assertTrue($this->schema->blockAllowsOrgType('Schedule', 'high_school'));
    }

    // ─── serverOwnedProps + storedOnlyProps ─────────────────────────────

    #[Test]
    public function exact_server_owned_prop_is_flagged_even_without_resolved_prefix(): void
    {
        // Fundraisers.resolvedFundraisers starts with "resolved" — the
        // heuristic AND the file both flag it. Do it via a prop that
        // ONLY appears in the file to prove file-driven works.
        // (Statistics doesn't have a "resolved-shaped" server-owned
        // prop today; use IntakeForm.formUuid — matches file exactly.)
        $block = new Block(type: 'IntakeForm', props: ['id' => 'x', 'formUuid' => 'abc-123']);
        $codes = array_map(fn ($i) => $i->code, $this->validator->validateBlock($block));
        $this->assertContains('server_owned_prop_authored', $codes);
    }

    #[Test]
    public function stored_only_prop_hero_visibility_passes_validation(): void
    {
        $block = new Block(type: 'Hero', props: [
            'id' => 'x',
            'heading' => 'Hi',
            'visibility' => ['showPreheading' => true, 'showHeading' => true, 'showSubheading' => true],
        ]);
        $issues = $this->validator->validateBlock($block);
        $errors = array_filter($issues, fn ($i) => $i->severity === 'error');
        $this->assertEmpty($errors, 'Hero.visibility is stored-only-with-no-editor-field; must not error');
    }

    // ─── reservedTopLevelSlugs (['view']) ───────────────────────────────

    #[Test]
    public function reserved_top_level_slugs_is_read_from_file(): void
    {
        $reserved = $this->schema->reservedTopLevelSlugs();
        $this->assertSame(['view'], $reserved, 'file today declares exactly one reserved top-level slug');
    }

    // ─── assetBearingProps (12 paths) ───────────────────────────────────

    #[Test]
    public function asset_bearing_props_lists_the_12_paths_the_file_declares(): void
    {
        $paths = $this->schema->assetBearingProps();
        $this->assertCount(12, $paths);
        // Spot-check a few.
        $this->assertContains('Hero.imageUrl', $paths);
        $this->assertContains('Image.src', $paths);
        $this->assertContains('Gallery.images[].src', $paths);
        $this->assertContains('TeamMembers.items[].photo', $paths);
        $this->assertContains('Grid.backgroundImage', $paths);
        // Sanity: no invented prefix.
        foreach ($paths as $p) {
            $this->assertMatchesRegularExpression('/^[A-Z][A-Za-z0-9]+\./', $p);
        }
    }

    // ─── richtext.props (5 paths) ───────────────────────────────────────

    #[Test]
    public function richtext_props_matches_file(): void
    {
        $expected = ['Accordion.items[].body', 'FAQ.items[].body', 'Text.body', 'TwoColumn.leftBody', 'TwoColumn.rightBody'];
        $actual = $this->schema->richtextProps();
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    // ─── slotPaths (Grid, Section, Table, Tabs) ─────────────────────────

    #[Test]
    public function slot_paths_names_the_four_slotted_blocks(): void
    {
        $slots = $this->schema->slotPaths();
        $this->assertArrayHasKey('Grid', $slots);
        $this->assertArrayHasKey('Section', $slots);
        $this->assertArrayHasKey('Table', $slots);
        $this->assertArrayHasKey('Tabs', $slots);
        $this->assertSame(['column1', 'column2', 'column3', 'column4'], $slots['Grid']);
    }

    // ─── opaqueProps ────────────────────────────────────────────────────

    #[Test]
    public function opaque_props_from_file_flow_through_validator_without_type_error(): void
    {
        // TeamRoster.selection.divisionIds[] and .teamIds[] are opaque
        // ID lists — the validator MUST NOT type-check their contents.
        $block = new Block(type: 'TeamRoster', props: [
            'id' => 'x',
            'selection' => [
                'divisionIds' => ['whatever-string-or-int', 42, ['nested' => true]],
                'teamIds' => [],
            ],
        ]);
        $issues = $this->validator->validateBlock($block);
        $wrongType = array_filter($issues, fn ($i) => $i->code === 'wrong_type');
        $this->assertEmpty($wrongType, 'opaque props reject nothing structurally');
    }
}
