<?php

declare(strict_types=1);

namespace Tests\Unit\ContractEmitter;

use App\Data\AssetRef;
use App\Data\OrgType;
use App\Data\SiteImport\Block;
use App\Services\ContractEmitter\AssetContext;
use App\Services\ContractEmitter\AssetLedger;
use App\Services\ContractEmitter\ContractSchema;
use App\Services\ContractEmitter\ContractSchemaValidator;
use App\Services\ContractEmitter\OrgTypeGate;
use App\Services\ContractEmitter\PuckToContractMapper;
use App\Services\ContractEmitter\RichTextSanitizer;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

// Finding 2 fix — Platform block mapper widening.
//
// First live cjfl run at orgType=league dropped 25 blocks:
//   PlatformTeam × 19, PlatformDivisions × 3, PlatformTeams × 1,
//   PlatformNews × 1, PlatformContacts × 1.
// Root cause: PuckToContractMapper::mapOne match had 8 arms + a
// default that emitted `unmappable_block_type` and dropped. No
// arm for any Platform* type. Bug was latent under orgType=club
// (tbirdhoops) because that rootnav has no LeagueInstance /
// TeamInstance nodes → PlatformBlockRenderer produced zero
// PuckOutputs → mapper never saw the shape.
//
// These tests pin each Platform → contract mapping and verify
// OrgTypeGate correctly gates the league-restricted targets.
final class PlatformBlockMappingTest extends TestCase
{
    private PuckToContractMapper $mapper;

    private ContractSchemaValidator $validator;

    private OrgTypeGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = ContractSchema::load();
        $this->mapper = new PuckToContractMapper(new RichTextSanitizer);
        $this->validator = new ContractSchemaValidator($schema);
        $this->gate = new OrgTypeGate($schema);
    }

    // ─── Positive pin: each Platform block maps to its contract target ──

    #[Test]
    public function platform_teams_maps_to_teams_sparse(): void
    {
        $out = $this->mapper->mapContent(
            [['type' => 'PlatformTeams', 'props' => ['org_id' => 'ngin-5765']]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertCount(1, $out->blocks);
        $this->assertSame('Teams', $out->blocks[0]->type);
        // Sparse: id-only.
        $this->assertSame(['id'], array_keys($out->blocks[0]->props));
        $this->assertContains(
            'platform_block_mapped_to_teams',
            array_map(fn ($d) => $d->code, $out->diagnostics),
        );
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function platform_team_maps_to_team_roster_sparse(): void
    {
        $out = $this->mapper->mapContent(
            [['type' => 'PlatformTeam', 'props' => ['org_id' => 'ngin-5765']]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('TeamRoster', $out->blocks[0]->type);
        $this->assertSame(['id'], array_keys($out->blocks[0]->props));
        $this->assertContains(
            'platform_block_mapped_to_team_roster',
            array_map(fn ($d) => $d->code, $out->diagnostics),
        );
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function platform_divisions_maps_to_sub_organizations_sparse(): void
    {
        $out = $this->mapper->mapContent(
            [['type' => 'PlatformDivisions', 'props' => ['org_id' => 'ngin-5765']]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('SubOrganizations', $out->blocks[0]->type);
        $this->assertSame(['id'], array_keys($out->blocks[0]->props));
        $this->assertContains(
            'platform_block_mapped_to_sub_organizations',
            array_map(fn ($d) => $d->code, $out->diagnostics),
        );
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function platform_news_maps_to_news_list_sparse(): void
    {
        $out = $this->mapper->mapContent(
            [['type' => 'PlatformNews', 'props' => ['org_id' => 'ngin-5765']]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('NewsList', $out->blocks[0]->type);
        $this->assertSame(['id'], array_keys($out->blocks[0]->props));
        // Critical: resolvedItems is server-owned per
        // x-teamlinkt.serverOwnedProps + hard rule #6. Must NOT be authored.
        $this->assertArrayNotHasKey('resolvedItems', $out->blocks[0]->props);
        $this->assertContains(
            'platform_block_mapped_to_news_list',
            array_map(fn ($d) => $d->code, $out->diagnostics),
        );
        $this->assertValidates($out->blocks);
    }

    #[Test]
    public function platform_contacts_maps_to_team_members_sparse(): void
    {
        $out = $this->mapper->mapContent(
            [['type' => 'PlatformContacts', 'props' => ['org_id' => 'ngin-5765']]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame('TeamMembers', $out->blocks[0]->type);
        $this->assertSame(['id'], array_keys($out->blocks[0]->props));
        $this->assertContains(
            'platform_block_mapped_to_team_members',
            array_map(fn ($d) => $d->code, $out->diagnostics),
        );
        $this->assertValidates($out->blocks);
    }

    // ─── OrgTypeGate interaction — league-restricted targets ────────────

    #[Test]
    public function teams_and_team_roster_pass_gate_under_league_orgtype(): void
    {
        // Under orgType=league (the cjfl case), Teams + TeamRoster
        // are permitted. This was the whole reason the bug surfaced —
        // OrgTypeGate would have caught them under club as a visible
        // `org_type_gate_dropped`; under league they pass through
        // and the (now-fixed) mapper produces the sparse contract block.
        $out = $this->mapper->mapContent(
            [
                ['type' => 'PlatformTeams', 'props' => ['org_id' => 'ngin-5765']],
                ['type' => 'PlatformTeam', 'props' => ['org_id' => 'ngin-5765']],
            ],
            $this->assetContext(),
            new AssetLedger,
        );
        [$gatedBlocks, $gatedDiagnostics] = $this->gate->apply($out->blocks, OrgType::League, 'cjfl');

        $this->assertCount(2, $gatedBlocks, 'Teams + TeamRoster must pass under orgType=league');
        $this->assertSame([], $gatedDiagnostics);
    }

    #[Test]
    public function teams_and_team_roster_dropped_under_club_orgtype_with_visible_diagnostic(): void
    {
        // Under orgType=club, Teams + TeamRoster are league-restricted
        // per x-teamlinkt.orgTypeGating.restrictedBlocks. The
        // OrgTypeGate produces a visible `org_type_gate_dropped`
        // diagnostic (was silent `unmappable_block_type` before the
        // Finding 2 fix).
        $out = $this->mapper->mapContent(
            [
                ['type' => 'PlatformTeams', 'props' => ['org_id' => 'ngin-63620']],
                ['type' => 'PlatformTeam', 'props' => ['org_id' => 'ngin-63620']],
            ],
            $this->assetContext(),
            new AssetLedger,
        );
        [$gatedBlocks, $gatedDiagnostics] = $this->gate->apply($out->blocks, OrgType::Club, 'tbirdhoops');

        $this->assertSame([], $gatedBlocks, 'Teams + TeamRoster must be dropped under orgType=club');
        $codes = array_map(fn ($d) => $d->code, $gatedDiagnostics);
        $this->assertContains('org_type_gate_dropped_block', $codes);
        $this->assertCount(2, $gatedDiagnostics);
    }

    #[Test]
    public function sub_organizations_news_list_team_members_pass_under_every_orgtype(): void
    {
        // These three targets are orgTypes: "all" per the schema —
        // they should pass under every org type. Test the ones the
        // cjfl run actually produced.
        foreach ([OrgType::Club, OrgType::Association, OrgType::League, OrgType::HighSchool, OrgType::Civic, OrgType::MultiLocation] as $orgType) {
            $out = $this->mapper->mapContent(
                [
                    ['type' => 'PlatformDivisions', 'props' => ['org_id' => 'x']],
                    ['type' => 'PlatformNews', 'props' => ['org_id' => 'x']],
                    ['type' => 'PlatformContacts', 'props' => ['org_id' => 'x']],
                ],
                $this->assetContext(),
                new AssetLedger,
            );
            [$gatedBlocks, $_] = $this->gate->apply($out->blocks, $orgType, 'x');

            $this->assertCount(3, $gatedBlocks, "SubOrganizations/NewsList/TeamMembers must pass under {$orgType->value}");
        }
    }

    // ─── Regression: unknown Platform* type still surfaces visibly ──────

    #[Test]
    public function unmapped_platform_variant_still_produces_visible_diagnostic(): void
    {
        // If a future PlatformBlockType enum value slips in without a
        // matching mapper arm, the default arm still fires. This is
        // the belt-and-braces catch — the mapper widening didn't
        // remove the safety net.
        $out = $this->mapper->mapContent(
            [['type' => 'PlatformUnknownFuture', 'props' => ['org_id' => 'x']]],
            $this->assetContext(),
            new AssetLedger,
        );
        $this->assertSame([], $out->blocks);
        $codes = array_map(fn ($d) => $d->code, $out->diagnostics);
        $this->assertContains('unmappable_block_type', $codes);
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
