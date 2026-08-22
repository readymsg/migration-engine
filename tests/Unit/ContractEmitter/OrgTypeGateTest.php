<?php

declare(strict_types=1);

namespace Tests\Unit\ContractEmitter;

use App\Data\OrgType;
use App\Data\SiteImport\Block;
use App\Services\ContractEmitter\ContractSchema;
use App\Services\ContractEmitter\OrgTypeGate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Contract Part II "Org types" — gated-out blocks are ERRORS, not
// silent drops. The user's Slice 15 constraint verbatim:
//   "a gated-out widget is an ERROR, not a silent drop. Emit a
//    diagnostic explaining we detected the pattern but the org
//    type can't render it."
final class OrgTypeGateTest extends TestCase
{
    private OrgTypeGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new OrgTypeGate(ContractSchema::load());
    }

    #[Test]
    public function standings_dropped_for_club_with_error_diagnostic(): void
    {
        $blocks = [
            new Block(type: 'Standings', props: ['id' => 'standings-abc']),
            new Block(type: 'Text', props: ['id' => 'text-abc', 'body' => 'Body']),
        ];
        [$kept, $diagnostics] = $this->gate->apply($blocks, OrgType::Club, 'schedule');

        $this->assertCount(1, $kept);
        $this->assertSame('Text', $kept[0]->type);
        $this->assertCount(1, $diagnostics);
        $this->assertSame('error', $diagnostics[0]->severity);
        $this->assertSame('org_type_gate_dropped_block', $diagnostics[0]->code);
        $this->assertStringContainsString('Standings', $diagnostics[0]->message);
        $this->assertStringContainsString('club', $diagnostics[0]->message);
        $this->assertStringContainsString('schedule', $diagnostics[0]->message);
    }

    #[Test]
    public function standings_kept_for_league(): void
    {
        $blocks = [new Block(type: 'Standings', props: ['id' => 'standings-abc'])];
        [$kept, $diagnostics] = $this->gate->apply($blocks, OrgType::League, 'schedule');
        $this->assertCount(1, $kept);
        $this->assertCount(0, $diagnostics);
    }

    #[Test]
    public function all_gated_widgets_dropped_together_with_per_block_diagnostics(): void
    {
        // Every league-only widget must fail for civic + multi_location
        // + club too. Standings/Scores/Schedule/Statistics/Suspensions/
        // TeamRoster/Teams — Contract Part II full gate list.
        $blocks = [
            new Block(type: 'Standings', props: ['id' => 'a']),
            new Block(type: 'Scores', props: ['id' => 'b']),
            new Block(type: 'Schedule', props: ['id' => 'c']),
        ];
        foreach ([OrgType::Club, OrgType::Civic, OrgType::MultiLocation] as $orgType) {
            [$kept, $diagnostics] = $this->gate->apply($blocks, $orgType, 'p');
            $this->assertCount(0, $kept, "no gated blocks survive for {$orgType->value}");
            $this->assertCount(3, $diagnostics);
            foreach ($diagnostics as $d) {
                $this->assertSame('error', $d->severity);
            }
        }
    }

    #[Test]
    public function unrestricted_widgets_are_never_dropped(): void
    {
        // Sponsors, NewsList, Locations, Grid, TeamMembers all
        // orgTypes=["all"]. Must survive on club.
        $blocks = [
            new Block(type: 'Sponsors', props: ['id' => 'a']),
            new Block(type: 'NewsList', props: ['id' => 'b']),
            new Block(type: 'Locations', props: ['id' => 'c']),
            new Block(type: 'Grid', props: ['id' => 'd', 'columns' => '3']),
            new Block(type: 'TeamMembers', props: ['id' => 'e']),
            new Block(type: 'Text', props: ['id' => 'f']),
        ];
        [$kept, $diagnostics] = $this->gate->apply($blocks, OrgType::Club, 'p');
        $this->assertCount(6, $kept);
        $this->assertCount(0, $diagnostics);
    }

    #[Test]
    public function unknown_block_types_default_to_permissive(): void
    {
        // A block type not in the catalogue is passed through (its
        // own validation-error surfaces via the catalogue check,
        // not through gating). Prevents double-erroring.
        $blocks = [new Block(type: 'MysteryBlock', props: ['id' => 'x'])];
        [$kept, $diagnostics] = $this->gate->apply($blocks, OrgType::Club, 'p');
        $this->assertCount(1, $kept);
        $this->assertCount(0, $diagnostics);
    }
}
