<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\DecisionAction;
use App\Data\DecisionEntry;
use App\Data\InventoryPage;
use App\Data\Manifest;
use App\Data\PlatformBlockType;
use App\Data\PlatformRenderFailure;
use App\Data\PlatformRenderResult;
use App\Data\PlatformRenderStatus;
use App\Data\PuckOutput;
use App\Data\SitePlan;
use App\Services\Schema\ComponentSchema;
use Illuminate\Support\Str;
use Spatie\LaravelData\DataCollection;

// GENERATE stage 3 slice 2e — deterministic renderer for platform_dynamic
// pages. NO LLM. Pure code over a closed table.
//
// Reads SitePlan.ledger filtered to DecisionAction::PlatformDynamic +
// SitePlan.kept_pages (target → InventoryPage for slug resolution) +
// Manifest.org_id. Emits one PuckOutput per entry containing a single
// Platform<X> block with props {org_id} — runtime React components own
// the empty-state, the engine just places the block.
//
// FAITHFUL-REBUILD GUARANTEE: every PlatformDynamic ledger entry →
// exactly one PuckOutput OR one PlatformRenderFailure. Three defensive
// failure modes (target-not-in-kept_pages, null-platform-block-type,
// no-schema-definition) are surfaced even though two are unreachable
// under current invariants — same discipline that's caught real bugs
// in adjacent stages.
final class PlatformBlockRenderer
{
    /** @var array<string, string>  PlatformBlockType value → Puck `type` string */
    private const TYPE_TO_PUCK = [
        'schedule' => 'PlatformSchedule',
        'scores' => 'PlatformScores',
        'standings' => 'PlatformStandings',
        'roster' => 'PlatformRoster',
        'teams' => 'PlatformTeams',
        'divisions' => 'PlatformDivisions',
        'contacts' => 'PlatformContacts',
        'calendar' => 'PlatformCalendar',
        'news' => 'PlatformNews',
        'team' => 'PlatformTeam',
    ];

    public function __construct(
        private readonly ComponentSchema $schema,
    ) {}

    public function run(SitePlan $plan, Manifest $manifest): PlatformRenderResult
    {
        $pagesByTarget = $this->indexKeptPagesByTarget($plan);

        /** @var array<int, PuckOutput> $pages */
        $pages = [];
        /** @var array<int, PlatformRenderFailure> $failures */
        $failures = [];

        /** @var array<int, DecisionEntry> $entries */
        $entries = $plan->ledger->entries->items();
        foreach ($entries as $entry) {
            if ($entry->action !== DecisionAction::PlatformDynamic) {
                continue;
            }

            [$puck, $failure] = $this->renderEntry($entry, $pagesByTarget, $manifest->org_id);
            if ($failure !== null) {
                $failures[] = $failure;

                continue;
            }
            if ($puck !== null) {
                $pages[] = $puck;
            }
        }

        return new PlatformRenderResult(
            pages: new DataCollection(PuckOutput::class, $pages),
            failures: new DataCollection(PlatformRenderFailure::class, $failures),
            status: $failures === [] ? PlatformRenderStatus::Complete : PlatformRenderStatus::Partial,
        );
    }

    /**
     * @param  array<string, InventoryPage>  $pagesByTarget
     * @return array{0: ?PuckOutput, 1: ?PlatformRenderFailure}
     */
    private function renderEntry(DecisionEntry $entry, array $pagesByTarget, string $orgId): array
    {
        $page = $pagesByTarget[$entry->target] ?? null;

        // Failure mode 1: ledger target doesn't match any kept_pages entry.
        // Defensive — RootNavPlanner::decideIa keeps PlatformDynamic pages
        // in kept_pages, so this is unreachable today. But if PLAN ever
        // changes that, surface the failure instead of silently dropping
        // the platform page.
        if ($page === null) {
            return [
                null,
                new PlatformRenderFailure(
                    page_slug: $this->slugFromTarget($entry->target),
                    page_title: $entry->target,
                    page_node_id: null,
                    reason: "no kept_pages entry matches ledger target '{$entry->target}'",
                ),
            ];
        }

        $slug = PageSlug::of($page);

        // Failure mode 2: PlatformDynamic with a null platform_block_type.
        // Defensive — the planner only emits PlatformDynamic with a non-
        // null type (RootNavPlanner.applyRecallBias line 604 + the two
        // deterministic emitters in deterministicAction).
        if ($entry->platform_block_type === null) {
            return [
                null,
                new PlatformRenderFailure(
                    page_slug: $slug,
                    page_title: $page->label,
                    page_node_id: $page->page_node_id,
                    reason: 'ledger entry action=platform_dynamic but platform_block_type is null',
                ),
            ];
        }

        $puckType = self::TYPE_TO_PUCK[$entry->platform_block_type->value] ?? null;
        $schemaDef = $puckType === null ? null : $this->schema->platformBlocks()[$puckType] ?? null;

        // Failure mode 3: enum value exists but ComponentSchema.platformBlocks()
        // doesn't define a matching component. Means the enum and the
        // schema drifted — adding a 10th PlatformBlockType without a
        // schema entry would land here. THIS one is reachable in practice.
        if ($puckType === null || $schemaDef === null) {
            $enumValue = $entry->platform_block_type->value;

            return [
                null,
                new PlatformRenderFailure(
                    page_slug: $slug,
                    page_title: $page->label,
                    page_node_id: $page->page_node_id,
                    reason: "no platformBlocks() definition for type '{$enumValue}'",
                ),
            ];
        }

        $puck = new PuckOutput(
            page_slug: $slug,
            content: [[
                'type' => $puckType,
                'props' => ['org_id' => $orgId],
            ]],
            root: ['title' => $page->label],
            zones: [],
        );

        return [$puck, null];
    }

    /**
     * @return array<string, InventoryPage> keyed by the planner's targetOf() string
     */
    private function indexKeptPagesByTarget(SitePlan $plan): array
    {
        /** @var array<string, InventoryPage> $out */
        $out = [];
        /** @var array<int, InventoryPage> $pages */
        $pages = $plan->kept_pages->items();
        foreach ($pages as $page) {
            $out[$this->targetOf($page)] = $page;
        }

        return $out;
    }

    // Same shape as RootNavPlanner::targetOf — duplicated here rather than
    // sharing because PLAN's targetOf is private and the join key needs to
    // be stable. If PLAN's targetOf shape ever changes, this MUST move with
    // it; the matching test in PlatformBlockRendererTest catches drift.
    private function targetOf(InventoryPage $page): string
    {
        if ($page->url !== null && $page->url !== '') {
            return $page->url;
        }
        if ($page->page_node_id !== null) {
            return 'page_node:'.$page->page_node_id;
        }

        return 'label:'.Str::slug($page->label);
    }

    // Best-effort slug for a failure entry when we can't find a matching
    // InventoryPage. Pure string fallback — never reached under current
    // invariants (failure mode 1 is defensive).
    private function slugFromTarget(string $target): string
    {
        if (str_starts_with($target, 'page_node:')) {
            return 'page-'.substr($target, strlen('page_node:'));
        }
        if (str_starts_with($target, 'label:')) {
            return substr($target, strlen('label:'));
        }

        return Str::slug($target);
    }
}
