<?php

declare(strict_types=1);

namespace Tests\Unit\Plan;

use App\Data\AssetRef;
use App\Data\Brand;
use App\Data\ClassificationResponse;
use App\Data\ContentRef;
use App\Data\DecisionAction;
use App\Data\DecisionEntry;
use App\Data\InventoryPage;
use App\Data\Manifest;
use App\Data\NavNode;
use App\Data\PlatformBlockType;
use App\Data\SiteStructure;
use App\Services\Plan\RootNavPlanner;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\Support\Plan\FakeClassifierAgent;
use Tests\Support\Plan\RealManifests;
use Tests\TestCase;

final class PlannerTest extends TestCase
{
    #[Test]
    public function ledger_covers_every_page_for_stthomas_and_skips_external_and_dynamic(): void
    {
        $manifest = RealManifests::stthomas();
        $agent = new FakeClassifierAgent;
        $plan = (new RootNavPlanner($agent))->plan($manifest);

        $entries = $plan->ledger->entries->items();
        $this->assertCount(18, $entries, 'stthomas has 7 top-level + 11 About Us children');

        // The "Dibs" toolsLink sibling — under the v1 SE-platform-link rule
        // it is parked (removed from the rebuild), not kept.
        $dibs = $this->ledgerEntryFor($entries, 'https://www.stthomassoccer.com/dib_sessions/index');
        $this->assertNotNull($dibs);
        $this->assertSame(DecisionAction::Park, $dibs->action);
        $this->assertSame(1.0, $dibs->confidence);
        $this->assertStringContainsString('SE platform link', $dibs->reason);

        // The "Swag/Spirit Wear" LinkNode is non-SE external — kept as a link.
        $swag = $this->ledgerEntryByReasonFragment($entries, 'LinkNode external link');
        $this->assertNotNull($swag);
        $this->assertSame(DecisionAction::Keep, $swag->action);

        // External + dynamic + SE-platform pages NEVER reach the LLM — only
        // the 16 ambiguous Page-kind siblings/children do.
        $this->assertCount(16, $agent->seen);
        foreach ($agent->seen as $page) {
            $this->assertSame('page', $page->kind, "LLM saw a non-page: {$page->label} ({$page->kind})");
        }

        // Batching: ≤ 20 per Haiku call.
        $this->assertCount(1, $agent->batches);
        $this->assertLessThanOrEqual(20, count($agent->batches[0]));

        // Nav: 6 top-level survivors (Dibs is parked → absent from nav).
        $this->assertCount(6, $plan->nav);

        // Kept pages: 17 (everything except Dibs).
        $this->assertCount(17, $plan->kept_pages);
    }

    #[Test]
    public function ledger_for_langdondiamonds_handles_waterworld_and_calendar(): void
    {
        $manifest = RealManifests::langdondiamonds();
        $agent = new FakeClassifierAgent;
        $plan = (new RootNavPlanner($agent))->plan($manifest);

        $entries = $plan->ledger->entries->items();
        $this->assertCount(19, $entries);

        // Calendar — deterministic platform_dynamic via node_type=Calendar,
        // never reaches the LLM. Same "zero live SE dependency" rule that
        // applies to Teams / Standings etc.
        $calendar = $this->ledgerEntryByReasonFragment($entries, 'calendar block');
        $this->assertNotNull($calendar);
        $this->assertSame(DecisionAction::PlatformDynamic, $calendar->action);
        $this->assertSame(PlatformBlockType::Calendar, $calendar->platform_block_type);
        $this->assertSame(1.0, $calendar->confidence);

        // Dibs toolsLink — parked under SE-platform rule.
        $dibs = $this->ledgerEntryFor($entries, 'https://www.langdondiamonds.ca/dib_sessions/index');
        $this->assertNotNull($dibs);
        $this->assertSame(DecisionAction::Park, $dibs->action);
        $this->assertStringContainsString('SE platform link', $dibs->reason);

        // Only Page kind reaches the LLM, and registration links bypass it.
        // langdondiamonds has "League Registration" + "Tournament Registration"
        // at top-level — both caught deterministically by the registration
        // rule (kept with retarget note). 17 Page-kind − 2 registration = 15.
        $this->assertCount(15, $agent->seen);
        foreach ($agent->seen as $page) {
            $this->assertSame('page', $page->kind);
        }

        // The two registration entries are in the ledger with the retarget note.
        $registrationEntries = array_values(array_filter(
            $entries,
            static fn (DecisionEntry $e) => str_contains($e->reason, 'registration link'),
        ));
        $this->assertCount(2, $registrationEntries, 'League + Tournament Registration must match');
        foreach ($registrationEntries as $entry) {
            $this->assertSame(DecisionAction::Keep, $entry->action);
            $this->assertStringContainsString('retarget to TeamLinkt secure registration URL', $entry->reason);
        }
    }

    #[Test]
    public function low_confidence_drop_or_park_becomes_keep_with_model_reason_preserved(): void
    {
        $manifest = RealManifests::stthomas();
        $agent = new FakeClassifierAgent;
        $agent->respondWith(static fn (InventoryPage $p): ClassificationResponse => new ClassificationResponse(
            action: DecisionAction::Drop,
            confidence: 0.4,                 // below 0.80 threshold
            reason: 'looks stale',
        ));

        $plan = (new RootNavPlanner($agent))->plan($manifest);

        $llmEntries = array_values(array_filter(
            $plan->ledger->entries->items(),
            static fn (DecisionEntry $e) => str_contains($e->reason, 'recall-biased keep'),
        ));
        $this->assertCount(16, $llmEntries);
        foreach ($llmEntries as $entry) {
            $this->assertSame(DecisionAction::Keep, $entry->action);
            $this->assertStringContainsString('model wanted drop', $entry->reason);
            $this->assertStringContainsString('@ 0.40', $entry->reason);
            $this->assertStringContainsString('looks stale', $entry->reason);
        }

        // Faithful rebuild minus the deterministic Dibs SE-platform park.
        $this->assertCount(6, $plan->nav);
        $this->assertCount(17, $plan->kept_pages);
    }

    #[Test]
    public function high_confidence_drop_becomes_park_reversible_in_v1(): void
    {
        $manifest = RealManifests::stthomas();
        $agent = new FakeClassifierAgent;
        $agent->respondWith(static fn (InventoryPage $p): ClassificationResponse => new ClassificationResponse(
            action: DecisionAction::Drop,
            confidence: 0.95,
            reason: 'definitely stale',
        ));

        $plan = (new RootNavPlanner($agent))->plan($manifest);

        $drops = array_values(array_filter(
            $plan->ledger->entries->items(),
            static fn (DecisionEntry $e) => $e->action === DecisionAction::Drop,
        ));
        $this->assertCount(0, $drops, 'v1 never emits a Drop — all become Park');

        // Filter to LLM-derived parks (vs. the deterministic Dibs SE park).
        $llmDerivedParks = array_values(array_filter(
            $plan->ledger->entries->items(),
            static fn (DecisionEntry $e) => $e->action === DecisionAction::Park
                && str_contains($e->reason, 'high-confidence drop parked'),
        ));
        $this->assertCount(16, $llmDerivedParks);
        foreach ($llmDerivedParks as $entry) {
            $this->assertStringContainsString('v1 never deletes', $entry->reason);
            $this->assertStringContainsString('definitely stale', $entry->reason);
        }

        // All 16 LLM pages parked + Dibs parked. Only the LinkNode
        // Swag/Spirit Wear (depth=1 external) survives.
        $this->assertCount(0, $plan->nav);
        $this->assertCount(1, $plan->kept_pages);
    }

    #[Test]
    public function high_confidence_park_passes_through_unchanged(): void
    {
        $manifest = RealManifests::stthomas();
        $agent = new FakeClassifierAgent;
        $agent->respondWith(static fn (InventoryPage $p): ClassificationResponse => new ClassificationResponse(
            action: DecisionAction::Park,
            confidence: 0.85,                // strictly > 0.80 threshold
            reason: 'placeholder page (Coming Soon)',
        ));

        $plan = (new RootNavPlanner($agent))->plan($manifest);

        // Filter to LLM-derived parks; the model's exact reason is preserved.
        $llmParks = array_values(array_filter(
            $plan->ledger->entries->items(),
            static fn (DecisionEntry $e) => $e->action === DecisionAction::Park
                && $e->reason === 'placeholder page (Coming Soon)',
        ));
        $this->assertCount(16, $llmParks);
        foreach ($llmParks as $entry) {
            $this->assertSame(0.85, $entry->confidence);
        }
    }

    #[Test]
    public function exactly_080_park_or_drop_falls_to_keep_strict_threshold(): void
    {
        foreach ([DecisionAction::Park, DecisionAction::Drop] as $modelAction) {
            $manifest = RealManifests::stthomas();
            $agent = new FakeClassifierAgent;
            $agent->respondWith(static fn (InventoryPage $p): ClassificationResponse => new ClassificationResponse(
                action: $modelAction,
                confidence: 0.80,
                reason: 'borderline value',
            ));

            $plan = (new RootNavPlanner($agent))->plan($manifest);

            // No LLM-derived parks/drops — they all became recall-biased keeps.
            $llmDerivedParks = array_values(array_filter(
                $plan->ledger->entries->items(),
                static fn (DecisionEntry $e) => $e->action === DecisionAction::Park
                    && str_contains($e->reason, 'borderline value'),
            ));
            $this->assertCount(0, $llmDerivedParks, "exactly-0.80 {$modelAction->value} must NOT honor the model");

            $drops = array_values(array_filter(
                $plan->ledger->entries->items(),
                static fn (DecisionEntry $e) => $e->action === DecisionAction::Drop,
            ));
            $this->assertCount(0, $drops);

            $keepsWithRecallReason = array_values(array_filter(
                $plan->ledger->entries->items(),
                static fn (DecisionEntry $e) => str_contains($e->reason, 'recall-biased keep'),
            ));
            $this->assertCount(16, $keepsWithRecallReason);
            foreach ($keepsWithRecallReason as $entry) {
                $this->assertSame(DecisionAction::Keep, $entry->action);
                $this->assertStringContainsString("model wanted {$modelAction->value}", $entry->reason);
                $this->assertStringContainsString('@ 0.80', $entry->reason);
                $this->assertStringContainsString('borderline value', $entry->reason);
            }

            // Faithful rebuild minus the Dibs deterministic park = 17 kept.
            $this->assertCount(17, $plan->kept_pages);
        }
    }

    #[Test]
    public function model_merge_is_suggestion_only_page_kept_with_target_in_reason(): void
    {
        $manifest = RealManifests::stthomas();
        $agent = new FakeClassifierAgent;
        $agent->respondWith(static fn (InventoryPage $p): ClassificationResponse => new ClassificationResponse(
            action: DecisionAction::Merge,
            confidence: 0.90,
            reason: 'closely related to Programs',
            merged_into: 'https://www.stthomassoccer.com/page/show/3060737-programs',
        ));

        $plan = (new RootNavPlanner($agent))->plan($manifest);

        $merges = array_values(array_filter(
            $plan->ledger->entries->items(),
            static fn (DecisionEntry $e) => $e->action === DecisionAction::Merge,
        ));
        $this->assertCount(0, $merges, 'v1 never emits MERGE — engine does not auto-fold pages');

        $kept = array_values(array_filter(
            $plan->ledger->entries->items(),
            static fn (DecisionEntry $e) => str_contains($e->reason, 'model suggested merge'),
        ));
        $this->assertCount(16, $kept);
        foreach ($kept as $entry) {
            $this->assertSame(DecisionAction::Keep, $entry->action);
            $this->assertStringContainsString('into https://www.stthomassoccer.com/page/show/3060737-programs', $entry->reason);
            $this->assertStringContainsString('closely related to Programs', $entry->reason);
            $this->assertNull($entry->merged_into);
        }

        $this->assertCount(6, $plan->nav);
        $this->assertCount(17, $plan->kept_pages);
    }

    // ─── platform_dynamic: deterministic name-map ──────────────────────────

    #[Test]
    public function tenacity_teams_with_children_is_platform_dynamic_and_descendants_subsumed(): void
    {
        $manifest = RealManifests::tenacityvolleyball();
        $agent = new FakeClassifierAgent;
        $plan = (new RootNavPlanner($agent))->plan($manifest);

        $entries = $plan->ledger->entries->items();

        // TEAMS top-level — deterministic platform_dynamic/teams.
        $teams = $this->ledgerEntryByReasonFragment($entries, "name-matched: 'TEAMS'");
        $this->assertNotNull($teams);
        $this->assertSame(DecisionAction::PlatformDynamic, $teams->action);
        $this->assertSame(PlatformBlockType::Teams, $teams->platform_block_type);
        $this->assertSame(1.0, $teams->confidence);

        // The 3 TEAMS children (11s & 12s, 13s & 14s, 15s-18s) are SUBSUMED
        // by the Teams block. They appear in the ledger (recoverable) but
        // are absent from nav and kept_pages, and were NOT sent to the LLM.
        $subsumed = array_values(array_filter(
            $entries,
            static fn (DecisionEntry $e) => $e->action === DecisionAction::Subsumed,
        ));
        $this->assertCount(3, $subsumed, 'all 3 TEAMS children should be Subsumed');
        foreach ($subsumed as $entry) {
            $this->assertStringContainsString("subsumed by parent teams block at 'TEAMS'", $entry->reason);
            $this->assertSame(1.0, $entry->confidence);
        }

        // None of TEAMS or its descendants reach the LLM.
        $seenLabels = array_map(static fn (InventoryPage $p): string => $p->label, $agent->seen);
        $this->assertNotContains('TEAMS', $seenLabels);
        $this->assertNotContains('11s & 12s', $seenLabels);
        $this->assertNotContains('13s & 14s', $seenLabels);
        $this->assertNotContains('15s-18s', $seenLabels);

        // Subsumed descendants are absent from kept_pages and nav; TEAMS
        // itself IS in both (it's the platform_dynamic block).
        $keptLabels = array_map(
            static fn (InventoryPage $p): string => $p->label,
            $plan->kept_pages->items(),
        );
        $this->assertContains('TEAMS', $keptLabels);
        $this->assertNotContains('11s & 12s', $keptLabels);
        $this->assertNotContains('13s & 14s', $keptLabels);
        $this->assertNotContains('15s-18s', $keptLabels);
    }

    #[Test]
    public function descendants_of_deterministic_platform_dynamic_are_subsumed_in_synthetic_tree(): void
    {
        // Build a tree: Standings (name-matched → PD/Standings) with 3 child
        // pages. All 3 children must be Subsumed before phase 2; the LLM
        // must see zero pages from this subtree.
        $children = [
            $this->navNode('Eastern Division', '/east', 201),
            $this->navNode('Western Division', '/west', 202),
            $this->navNode('Central Division', '/central', 203),
        ];
        $standings = new NavNode(
            label: 'Standings',
            url: '/standings',
            kind: 'page',
            children: new DataCollection(NavNode::class, $children),
            node_type: 'Page',
            page_node_id: 100,
        );
        $manifest = $this->manifestFromNavNodes([$standings]);

        $agent = new FakeClassifierAgent;
        $plan = (new RootNavPlanner($agent))->plan($manifest);

        $entries = $plan->ledger->entries->items();
        $this->assertCount(4, $entries);

        $this->assertSame(DecisionAction::PlatformDynamic, $entries[0]->action);
        $this->assertSame(PlatformBlockType::Standings, $entries[0]->platform_block_type);
        for ($i = 1; $i <= 3; $i++) {
            $this->assertSame(DecisionAction::Subsumed, $entries[$i]->action);
            $this->assertStringContainsString(
                "subsumed by parent standings block at 'Standings'",
                $entries[$i]->reason,
            );
        }

        // No LLM calls — descendants were never queued.
        $this->assertCount(0, $agent->seen);

        // Subsumed absent from kept_pages and nav.
        $this->assertCount(1, $plan->kept_pages);
        $this->assertCount(1, $plan->nav);
    }

    #[Test]
    public function calendar_and_news_node_types_route_to_platform_dynamic_blocks(): void
    {
        // SVA has both a Calendar (node_type=Calendar) and a News
        // (node_type=NewsNode). Both should route deterministically to
        // platform_dynamic with the right PlatformBlockType — not via the
        // LLM, not as the old 'Dynamic' action.
        $manifest = RealManifests::surprisevolleyballacademy();
        $agent = new FakeClassifierAgent;
        $plan = (new RootNavPlanner($agent))->plan($manifest);

        $entries = $plan->ledger->entries->items();

        $calendar = $this->ledgerEntryByReasonFragment($entries, 'calendar block');
        $this->assertNotNull($calendar);
        $this->assertSame(DecisionAction::PlatformDynamic, $calendar->action);
        $this->assertSame(PlatformBlockType::Calendar, $calendar->platform_block_type);
        $this->assertSame(1.0, $calendar->confidence);
        $this->assertStringContainsString('node_type=Calendar', $calendar->reason);

        $news = $this->ledgerEntryByReasonFragment($entries, 'news block');
        $this->assertNotNull($news);
        $this->assertSame(DecisionAction::PlatformDynamic, $news->action);
        $this->assertSame(PlatformBlockType::News, $news->platform_block_type);
        $this->assertSame(1.0, $news->confidence);
        $this->assertStringContainsString('node_type=NewsNode', $news->reason);

        // Neither reaches the LLM.
        $seenLabels = array_map(static fn (InventoryPage $p): string => $p->label, $agent->seen);
        $this->assertNotContains('Calendar', $seenLabels);
        $this->assertNotContains('News', $seenLabels);
    }

    #[Test]
    public function name_map_matches_standings_schedule_scores_roster_divisions(): void
    {
        $manifest = $this->manifestWithLabels([
            ['Standings', null],
            ['Schedule', null],
            ['Schedules', null],
            ['Scores', null],
            ['Results', null],
            ['Roster', null],
            ['Rosters', null],
            ['Divisions', null],
            ['Division', null],
        ]);
        $agent = new FakeClassifierAgent;
        $plan = (new RootNavPlanner($agent))->plan($manifest);

        $expected = [
            'Standings' => PlatformBlockType::Standings,
            'Schedule' => PlatformBlockType::Schedule,
            'Schedules' => PlatformBlockType::Schedule,
            'Scores' => PlatformBlockType::Scores,
            'Results' => PlatformBlockType::Scores,
            'Roster' => PlatformBlockType::Roster,
            'Rosters' => PlatformBlockType::Roster,
            'Divisions' => PlatformBlockType::Divisions,
            'Division' => PlatformBlockType::Divisions,
        ];
        foreach ($expected as $label => $blockType) {
            $entry = $this->ledgerEntryByReasonFragment(
                $plan->ledger->entries->items(),
                "name-matched: '{$label}'",
            );
            $this->assertNotNull($entry, "{$label} should be name-matched");
            $this->assertSame(DecisionAction::PlatformDynamic, $entry->action);
            $this->assertSame($blockType, $entry->platform_block_type);
        }

        // None of these reach the LLM.
        $this->assertCount(0, $agent->seen);
    }

    #[Test]
    public function leaf_teams_page_without_children_is_not_platform_dynamic(): void
    {
        // A "Teams" leaf node (no children) is content, not a directory.
        // Gate: the 'teams' name-map requires has_children > 0.
        $manifest = $this->manifestWithLabels([['Teams', null]]);
        $agent = new FakeClassifierAgent;
        $plan = (new RootNavPlanner($agent))->plan($manifest);

        $teams = $plan->ledger->entries->items()[0];
        $this->assertNotSame(DecisionAction::PlatformDynamic, $teams->action);
        $this->assertNull($teams->platform_block_type);
        // It went to the LLM (FakeClassifierAgent default = keep@0.85).
        $this->assertCount(1, $agent->seen);
        $this->assertSame('Teams', $agent->seen[0]->label);
    }

    #[Test]
    public function ambiguous_labels_tryouts_recruiting_programs_events_camps_go_to_llm(): void
    {
        // Conservative principle: a false platform_dynamic is destructive
        // (real content replaced by an empty block). Ambiguous words like
        // 'tryouts'/'recruiting'/'programs'/'events'/'camps' must NOT be
        // name-matched — let the LLM judge each one in context.
        $ambiguous = ['Tryouts', 'Recruiting', 'Programs', 'Events', 'Camps', 'Contact Us', 'Contacts'];
        $entries = array_map(static fn (string $label): array => [$label, null], $ambiguous);
        $manifest = $this->manifestWithLabels($entries);
        $agent = new FakeClassifierAgent;
        (new RootNavPlanner($agent))->plan($manifest);

        $this->assertCount(count($ambiguous), $agent->seen);
        $seenLabels = array_map(static fn (InventoryPage $p): string => $p->label, $agent->seen);
        foreach ($ambiguous as $label) {
            $this->assertContains($label, $seenLabels, "{$label} must reach the LLM, not be auto-classified");
        }
    }

    // ─── platform_dynamic: LLM fallback ────────────────────────────────────

    #[Test]
    public function llm_high_confidence_platform_dynamic_is_honored(): void
    {
        $manifest = $this->manifestWithLabels([['Game Schedule', null]]);
        $agent = new FakeClassifierAgent;
        $agent->respondWith(static fn (InventoryPage $p): ClassificationResponse => new ClassificationResponse(
            action: DecisionAction::PlatformDynamic,
            confidence: 0.95,
            reason: 'live schedule grid',
            platform_block_type: PlatformBlockType::Schedule,
        ));

        $plan = (new RootNavPlanner($agent))->plan($manifest);

        $entry = $plan->ledger->entries->items()[0];
        $this->assertSame(DecisionAction::PlatformDynamic, $entry->action);
        $this->assertSame(PlatformBlockType::Schedule, $entry->platform_block_type);
        $this->assertStringContainsString('LLM-classified', $entry->reason);
        $this->assertStringContainsString('live schedule grid', $entry->reason);
    }

    #[Test]
    public function llm_low_confidence_platform_dynamic_falls_to_recall_biased_keep(): void
    {
        // A false platform_dynamic destroys real content. <=0.80 → keep.
        $manifest = $this->manifestWithLabels([['Game Schedule', null]]);
        $agent = new FakeClassifierAgent;
        $agent->respondWith(static fn (InventoryPage $p): ClassificationResponse => new ClassificationResponse(
            action: DecisionAction::PlatformDynamic,
            confidence: 0.65,
            reason: 'might be a schedule listing',
            platform_block_type: PlatformBlockType::Schedule,
        ));

        $plan = (new RootNavPlanner($agent))->plan($manifest);

        $entry = $plan->ledger->entries->items()[0];
        $this->assertSame(DecisionAction::Keep, $entry->action);
        $this->assertNull($entry->platform_block_type);
        $this->assertStringContainsString('recall-biased keep', $entry->reason);
        $this->assertStringContainsString('model wanted platform_dynamic/schedule', $entry->reason);
    }

    // ─── SE platform link removal vs CDN asset safety ──────────────────────

    #[Test]
    public function sva_sports_engine_link_is_parked_via_se_platform_rule_not_sent_to_llm(): void
    {
        $manifest = RealManifests::surprisevolleyballacademy();
        $agent = new FakeClassifierAgent;
        $plan = (new RootNavPlanner($agent))->plan($manifest);

        // The "Sports Engine" sub-link under Tryouts/Open House.
        $entries = $plan->ledger->entries->items();
        $sportsEngine = null;
        foreach ($entries as $e) {
            if (str_contains($e->reason, 'SE platform link') && str_contains($e->target, '/sportsengine')) {
                $sportsEngine = $e;
                break;
            }
        }
        $this->assertNotNull($sportsEngine, 'the "Sports Engine" link must be parked via SE-platform rule');
        $this->assertSame(DecisionAction::Park, $sportsEngine->action);
        $this->assertSame(1.0, $sportsEngine->confidence);

        // NOT sent to the LLM.
        foreach ($agent->seen as $page) {
            $this->assertNotSame('Sports Engine', $page->label, 'Sports Engine link must NOT reach the LLM');
        }

        // Same deterministic rule covers the SVA "Dibs" toolsLink.
        $dibs = $this->ledgerEntryFor(
            $entries,
            'https://www.surprisevolleyballacademy.org/dib_sessions/index',
        );
        $this->assertNotNull($dibs);
        $this->assertSame(DecisionAction::Park, $dibs->action);
    }

    #[Test]
    public function sportngin_cdn_asset_url_in_nav_is_not_parked_as_se_platform(): void
    {
        // A nav node whose URL happens to point at a sportngin.com CDN asset
        // (banner_graphic, logo_graphic, etc.) must NOT trigger the SE-platform
        // rule — those are content assets that GENERATE re-hosts to S3, not
        // platform tool links. The rule matches on PATH segments
        // (/sportsengine, /dib_sessions, …) not on the host name.
        $manifest = $this->manifestWithLabels([
            ['Resources', 'https://cdn1.sportngin.com/attachments/banner_graphic/12345/banner.png'],
            ['Logo', 'https://assets.ngin.com/site_files/13992/favicon.ico'],
            ['Theme', 'https://app-assets1.sportngin.com/javascripts/themes/itasca/theme.js'],
        ]);
        $agent = new FakeClassifierAgent;
        $plan = (new RootNavPlanner($agent))->plan($manifest);

        foreach ($plan->ledger->entries as $entry) {
            $this->assertNotSame(
                DecisionAction::Park,
                $entry->action,
                "sportngin/ngin CDN URL in nav must not trip SE-platform: target={$entry->target}"
            );
            $this->assertStringNotContainsString('SE platform link', $entry->reason);
        }
    }

    // ─── Registration retargeting ──────────────────────────────────────────

    #[Test]
    public function registration_link_is_kept_with_retarget_note(): void
    {
        $manifest = $this->manifestWithLabels([
            ['Register Now', null],
            ['Online Registration', null],
            ['Sign Up', null],     // tight matching — 'sign up' is NOT a registration trigger word
        ]);
        $agent = new FakeClassifierAgent;
        $plan = (new RootNavPlanner($agent))->plan($manifest);

        $entries = $plan->ledger->entries->items();
        $registrationEntries = array_values(array_filter(
            $entries,
            static fn (DecisionEntry $e) => str_contains($e->reason, 'registration link'),
        ));
        $this->assertCount(2, $registrationEntries, "'Register Now' + 'Online Registration' should match");
        foreach ($registrationEntries as $entry) {
            $this->assertSame(DecisionAction::Keep, $entry->action);
            $this->assertSame(1.0, $entry->confidence);
            $this->assertStringContainsString('retarget to TeamLinkt secure registration URL', $entry->reason);
        }

        // 'Sign Up' does NOT match the registration rule; falls to LLM.
        $seenLabels = array_map(static fn (InventoryPage $p): string => $p->label, $agent->seen);
        $this->assertContains('Sign Up', $seenLabels);
        $this->assertNotContains('Register Now', $seenLabels);
        $this->assertNotContains('Online Registration', $seenLabels);
    }

    // ─── helpers ──────────────────────────────────────────────────────────

    /**
     * @param  array<int, DecisionEntry>  $entries
     */
    private function ledgerEntryFor(array $entries, string $target): ?DecisionEntry
    {
        foreach ($entries as $e) {
            if ($e->target === $target) {
                return $e;
            }
        }

        return null;
    }

    /**
     * @param  array<int, DecisionEntry>  $entries
     */
    private function ledgerEntryByReasonFragment(array $entries, string $fragment): ?DecisionEntry
    {
        foreach ($entries as $e) {
            if (str_contains($e->reason, $fragment)) {
                return $e;
            }
        }

        return null;
    }

    /**
     * Synthetic Manifest with one top-level NavNode per supplied entry.
     * Each $entries[i] is [label, ?url] — null url defaults to a generated
     * /page/show/<n>-<slug> path. node_type='Page', no children.
     *
     * @param  array<int, array{0: string, 1: ?string}>  $entries
     */
    private function manifestWithLabels(array $entries): Manifest
    {
        $nav = [];
        foreach ($entries as $i => $entry) {
            $label = $entry[0];
            $url = $entry[1] ?? null;
            $nav[] = new NavNode(
                label: $label,
                url: $url ?? '/page/show/'.($i + 100).'-'.Str::slug($label),
                kind: 'page',
                children: new DataCollection(NavNode::class, []),
                node_type: 'Page',
                page_node_id: $i + 100,
            );
        }

        return $this->manifestFromNavNodes($nav);
    }

    /**
     * Wraps already-built NavNodes (top-level) into a minimal Manifest.
     *
     * @param  array<int, NavNode>  $nav
     */
    private function manifestFromNavNodes(array $nav): Manifest
    {
        $total = 0;
        $this->countNodes($nav, $total);

        return new Manifest(
            source_url: 'https://example.test/',
            org_id: 'test-org',
            structure: new SiteStructure(
                nav: new DataCollection(NavNode::class, $nav),
                pages_total: $total,
            ),
            provisioning: null,
            brand: new Brand(logo_source: 'flag', logo_asset_ref: null, palette: [], voice_hint: null),
            content_refs: new DataCollection(ContentRef::class, []),
            asset_refs: new DataCollection(AssetRef::class, []),
            confidence: 0.0,
            flags: [],
        );
    }

    private function navNode(string $label, string $url, int $id): NavNode
    {
        return new NavNode(
            label: $label,
            url: $url,
            kind: 'page',
            children: new DataCollection(NavNode::class, []),
            node_type: 'Page',
            page_node_id: $id,
        );
    }

    /**
     * @param  array<int, NavNode>  $nodes
     */
    private function countNodes(array $nodes, int &$total): void
    {
        foreach ($nodes as $node) {
            $total++;
            /** @var array<int, NavNode> $children */
            $children = $node->children->items();
            $this->countNodes($children, $total);
        }
    }
}
