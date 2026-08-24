<?php

declare(strict_types=1);

namespace Tests\Unit\Plan;

use App\Data\AssetRef;
use App\Data\Brand;
use App\Data\ContentExtractionFailure;
use App\Data\ContentRef;
use App\Data\DecisionAction;
use App\Data\DecisionEntry;
use App\Data\Manifest;
use App\Data\NavNode;
use App\Data\SitePlan;
use App\Data\SiteStructure;
use App\Services\Generate\ContentLoader;
use App\Services\Plan\RootNavPlanner;
use App\Services\Plan\SePlatformContentDetector;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\Support\Plan\FakeClassifierAgent;
use Tests\TestCase;

// Follow-up 3 pin — Contract Part II "Pages you should not create":
//
//   "Paginated duplicates (`/news/page/2`). Map the first page only."
//
// PLAN's deterministicAction parks any URL whose path ends in
// `/page/<int>` or `/p/<int>` (case-insensitive, optional trailing
// slash) with a `paginated_duplicate:` reason. DraftLanding surfaces
// them as info-severity `page_dropped_paginated_duplicate` diagnostics.
final class PaginatedDuplicateFilterTest extends TestCase
{
    private RootNavPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new RootNavPlanner(
            new FakeClassifierAgent,
            new ContentLoader(disk: 'local'),
            new SePlatformContentDetector,
        );
    }

    #[Test]
    public function news_page_2_and_3_parked_as_paginated_duplicates(): void
    {
        $plan = $this->planFor([
            $this->navNode('News', '/news', 100),
            $this->navNode('News page 2', '/news/page/2', 200),
            $this->navNode('News page 3', '/news/page/3', 300),
        ]);

        $parks = $this->parkedPaginatedEntries($plan);
        $this->assertCount(2, $parks);
        foreach ($parks as $entry) {
            $this->assertStringStartsWith('paginated_duplicate:', $entry->reason);
            $this->assertStringContainsString('Pages you should not create', $entry->reason);
        }
    }

    #[Test]
    public function short_form_slash_p_slash_n_is_matched(): void
    {
        $plan = $this->planFor([
            $this->navNode('News', '/news', 100),
            $this->navNode('News p 2', '/news/p/2', 200),
        ]);

        $this->assertCount(1, $this->parkedPaginatedEntries($plan));
    }

    #[Test]
    public function trailing_slash_still_matches(): void
    {
        $plan = $this->planFor([
            $this->navNode('News', '/news', 100),
            $this->navNode('News page 2', '/news/page/2/', 200),
        ]);

        $this->assertCount(1, $this->parkedPaginatedEntries($plan));
    }

    // ─── Negative tests — must NOT false-park ───────────────────────────

    #[Test]
    public function base_page_without_page_suffix_is_never_parked(): void
    {
        // `/news`, `/about`, `/programs` — no `/page/N` suffix.
        $plan = $this->planFor([
            $this->navNode('News', '/news', 100),
            $this->navNode('About', '/about', 200),
            $this->navNode('Programs', '/programs', 300),
        ]);

        $this->assertCount(0, $this->parkedPaginatedEntries($plan));
    }

    #[Test]
    public function page_hyphen_2_slug_is_not_parked(): void
    {
        // `/page-2-committee` — a legit slug containing "page-2".
        // Regex requires a slash BEFORE `page`, not a hyphen.
        $plan = $this->planFor([
            $this->navNode('Page 2 Committee', '/page-2-committee', 100),
        ]);

        $this->assertCount(0, $this->parkedPaginatedEntries($plan));
    }

    #[Test]
    public function season_2_style_slug_is_not_parked(): void
    {
        // `/season-2` — legit slug. Regex requires literal `/page` or `/p`
        // followed by /digits.
        $plan = $this->planFor([
            $this->navNode('2026 Season', '/season-2', 100),
        ]);

        $this->assertCount(0, $this->parkedPaginatedEntries($plan));
    }

    // ─── helpers ────────────────────────────────────────────────────────

    private function navNode(string $label, string $url, int $nodeId): NavNode
    {
        return new NavNode(
            label: $label,
            url: $url,
            kind: 'page',
            children: new DataCollection(NavNode::class, []),
            node_type: 'Page',
            page_node_id: $nodeId,
            external_subtype: null,
        );
    }

    /**
     * @param  array<int, NavNode>  $nodes
     */
    private function planFor(array $nodes): SitePlan
    {
        $manifest = new Manifest(
            source_url: 'https://x.example',
            org_id: 'ngin-test',
            structure: new SiteStructure(
                nav: new DataCollection(NavNode::class, $nodes),
                pages_total: count($nodes),
            ),
            provisioning: null,
            brand: new Brand(logo_source: 'flag'),
            content_refs: new DataCollection(ContentRef::class, []),
            asset_refs: new DataCollection(AssetRef::class, []),
            confidence: 1.0,
            content_failures: new DataCollection(ContentExtractionFailure::class, []),
        );

        return $this->planner->plan($manifest);
    }

    /**
     * @return array<int, DecisionEntry>
     */
    private function parkedPaginatedEntries(SitePlan $plan): array
    {
        $out = [];
        foreach ($plan->ledger->entries as $entry) {
            if ($entry->action === DecisionAction::Park
                && str_starts_with($entry->reason, 'paginated_duplicate:')) {
                $out[] = $entry;
            }
        }

        return $out;
    }
}
