<?php

declare(strict_types=1);

namespace Tests\Unit\Generate;

use App\Data\BlockFillResult;
use App\Data\ConversionFailure;
use App\Data\ConversionStage;
use App\Data\ConversionStatus;
use App\Data\ResolvedNavItem;
use App\Data\ResolvedNavStatus;
use App\Services\Generate\Assembler;
use App\Services\Generate\BlockCoercer;
use App\Services\Generate\ContentLoader;
use App\Services\Generate\DraftLanding;
use App\Services\Generate\PlatformBlockRenderer;
use App\Services\Plan\RootNavPlanner;
use App\Services\Plan\SePlatformContentDetector;
use App\Services\Schema\DefaultPuckComponentSchema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Generate\RecordingProductClient;
use Tests\Support\Plan\FakeClassifierAgent;
use Tests\Support\Plan\RealManifests;
use Tests\TestCase;

// Fixture-replay test for slice 2f. End-to-end pipeline:
//
//   real Manifest (rootnav fixtures)
//     → PLAN (FakeClassifierAgent, offline)         → SitePlan
//   tbirdhoops BlockFillResult fixture
//     → Assembler                                    → AssemblyResult
//   SitePlan + Manifest
//     → PlatformBlockRenderer                        → PlatformRenderResult
//   all of the above
//     → DraftLanding                                 → ConversionResult
//
// ASSERTS INVARIANTS, NOT THE EXACT ARTIFACT. The page set the offline
// pipeline produces is sensitive to the offline/online discrepancy (the
// body-aware SePlatformContentDetector can't run without bodies — see
// CLAUDE.md "Known gaps" → offline replay). If the live fixture is
// regenerated later, the exact count of nav/pages MAY change without
// the lander itself being wrong. So this test does NOT hard-code the
// number of resolved entries, which specific label is Unresolved, or
// whether status comes out Completed vs Partial. Instead it asserts the
// properties that MUST hold for any well-formed input the lander is
// given:
//
//   1. Every nav entry with status=Resolved MUST key into page_map.
//      (The slug-reconciliation contract — the point of slice 2f.)
//   2. Every nav entry whose resolved page_slug is missing from
//      page_map MUST surface as Unresolved AND produce a
//      draft-landing ConversionFailure — never a silent broken link.
//      (Exception: kind=external NavItems legitimately have no page
//      and are marked UnmatchedExternal with no failure.)
//   3. createDraftSite is called iff status != Failed (the never-
//      auto-publish-aborted-conversions invariant). Partial conversions
//      still ship.
//   4. The page_map keys submitted to createDraftSite are exactly the
//      keys the lander built — no rewriting at the seam.
final class DraftLandingFixtureReplayTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../Fixtures/blockfill/tbirdhoops.json';

    #[Test]
    public function tbirdhoops_end_to_end_satisfies_lander_invariants(): void
    {
        $manifest = RealManifests::tbirdhoops();

        $planner = new RootNavPlanner(
            new FakeClassifierAgent,
            new ContentLoader(disk: 'local'),
            new SePlatformContentDetector,
        );
        $plan = $planner->plan($manifest);

        $this->assertFileExists(self::FIXTURE);
        $raw = file_get_contents(self::FIXTURE);
        $this->assertIsString($raw);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        $blockFill = BlockFillResult::from($decoded);

        $schema = new DefaultPuckComponentSchema;
        $assembly = (new Assembler(new BlockCoercer($schema)))->run($blockFill);
        $platform = (new PlatformBlockRenderer($schema))->run($plan, $manifest);

        $client = new RecordingProductClient;
        $client->returns('TBIRD_DRAFT', 'https://teamlinkt.test/drafts/TBIRD_DRAFT');
        $lander = new DraftLanding($client);

        $result = $lander->run(
            conversionId: 'tbirdhoops-replay',
            plan: $plan,
            assembly: $assembly,
            platform: $platform,
            manifest: $manifest,
        );

        // --- Invariant 3: createDraftSite is called iff status != Failed ---
        if ($result->status === ConversionStatus::Failed) {
            $this->assertCount(0, $client->calls, 'Failed conversions MUST NOT call createDraftSite');
            $this->assertNull($result->draft_id);
            $this->assertNull($result->draft_url);
        } else {
            $this->assertCount(1, $client->calls, 'non-Failed conversions land the draft');
            $this->assertNotNull($result->draft_id);
            $this->assertNotNull($result->draft_url);
            $this->assertSame($manifest->org_id, $client->calls[0]['org_id']);
            // --- Invariant 4: the submitted page_map is exactly what the lander built ---
            $this->assertSame(
                array_keys($result->page_map),
                array_keys($client->calls[0]['puck']),
                'createDraftSite payload keys == ConversionResult.page_map keys (no rewriting at the seam)',
            );
            $this->assertSame([], $client->calls[0]['provisioning'], 'v1 scope cut: provisioning always empty');
        }

        // --- Invariants 1+2: nav reconciliation contract ---
        // Indexed by status so the failure case is debuggable when a
        // nav entry lands in the wrong bucket.
        /** @var array<int, ResolvedNavItem> $nav */
        $nav = $result->nav->items();
        $this->assertNotCount(0, $nav, 'tbirdhoops has at least one depth-0 nav entry');

        $failureSlugs = [];
        /** @var array<int, ConversionFailure> $failuresAll */
        $failuresAll = $result->failures->items();
        foreach ($failuresAll as $f) {
            if ($f->stage === ConversionStage::DraftLanding) {
                $failureSlugs[$f->page_slug] = true;
            }
        }

        foreach ($nav as $r) {
            switch ($r->status) {
                case ResolvedNavStatus::Resolved:
                    // --- Invariant 1: Resolved → MUST key into page_map ---
                    $this->assertArrayHasKey(
                        $r->page_slug,
                        $result->page_map,
                        "Resolved nav '{$r->label}' has slug '{$r->page_slug}' that is NOT in page_map — slug reconciliation regressed",
                    );
                    break;

                case ResolvedNavStatus::Unresolved:
                    // --- Invariant 2a: Unresolved nav MUST have a matching draft-landing failure ---
                    $this->assertArrayNotHasKey(
                        $r->page_slug,
                        $result->page_map,
                        "Unresolved nav '{$r->label}' should not have a page_map entry (would be Resolved instead)",
                    );
                    $this->assertArrayHasKey(
                        $r->page_slug,
                        $failureSlugs,
                        "Unresolved nav '{$r->label}' (slug '{$r->page_slug}') MUST surface a draft-landing ConversionFailure — never a silent broken link",
                    );
                    break;

                case ResolvedNavStatus::UnmatchedExternal:
                    // --- Invariant 2b: external nav legitimately has no page, no failure ---
                    $this->assertArrayNotHasKey(
                        $r->page_slug,
                        $failureSlugs,
                        "UnmatchedExternal nav '{$r->label}' should NOT produce a draft-landing failure (external links are a nav-layer concern, not a draft-landing failure)",
                    );
                    break;
            }
        }

        // Light sanity: the page_map is non-empty (the fixture carries
        // FilledPages and the assembler emits PuckOutputs for them).
        // Doesn't hard-code the count — the fixture can grow or shrink.
        $this->assertNotSame([], $result->page_map, 'fixture has FilledPages → page_map is non-empty');
        $this->assertSame($manifest->org_id, $result->org_id);
        $this->assertSame('tbirdhoops-replay', $result->conversion_id);
    }
}
