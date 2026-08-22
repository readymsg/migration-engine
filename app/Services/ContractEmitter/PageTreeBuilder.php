<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\ConversionResult;
use App\Data\ResolvedNavItem;
use App\Data\ResolvedNavStatus;
use App\Data\SiteImport\Block;
use App\Data\SiteImport\Diagnostic;
use App\Data\SiteImport\Page;
use App\Data\SiteImport\PageData;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Optional;

// Assembles contract `pages[]` from our ConversionResult. Owns the
// slug rules from Contract Part II "Slug rules":
//
//   1. Exactly one page has slug=""" — the homepage.
//   2. Slugs UNIQUE case-insensitively.
//   3. `view` is a RESERVED top-level slug (SE entity-detail routes
//      win over page lookup). No slug may be `view` or start with
//      `view/`.
//   4. Slugs lowercase, hyphens, no leading/trailing `/`, no file
//      extensions.
//   5. `parentId` names another page's id in the SAME payload, or
//      is null for top-level.
//
// Homepage-picking heuristic (Contract Part II verbatim): "If the
// scrape gives you no obvious homepage, pick the page the site's
// own navigation links to most often, falling back to the
// shallowest URL." Our proxy for that: the first Resolved nav item
// (lowest navOrder among status=Resolved entries). Fallback: first
// key in page_map. Emits a diagnostic if we had to fall back.
//
// LAYERS NOT ADDRESSED HERE:
//   - Content mapping (Slice 5 / composed in Slice 9). Every Page
//     leaves this class with content=[] and Slice 9 fills it.
//   - parentId hierarchy. tbirdhoops has flat depth-0 nav so v1
//     emits every parentId as null; nested sites are handled when
//     a fixture actually needs it (Slice 16 broaden phase).
final class PageTreeBuilder
{
    public function build(ConversionResult $result): PageTree
    {
        $diagnostics = [];

        // Determine the homepage source-slug.
        [$homeSourceSlug, $homeDiag] = $this->pickHomepage($result);
        if ($homeDiag !== null) {
            $diagnostics[] = $homeDiag;
        }

        // Order pages: nav-resolved first (in nav order), then the
        // rest of page_map by their existing key order. Anything in
        // page_map that isn't in nav becomes showInNav=false.
        $navIndex = $this->navIndex($result->nav);
        $orderedSourceSlugs = $this->orderedPageOrder($result, $navIndex);

        $pages = [];
        $usedSlugs = [];
        $pageIdBySourceSlug = [];

        $navOrderCounter = 0;
        foreach ($orderedSourceSlugs as $sourceSlug) {
            $navEntry = $navIndex[$sourceSlug] ?? null;
            $isHome = $sourceSlug === $homeSourceSlug;
            $showInNav = $navEntry !== null && $navEntry->status === ResolvedNavStatus::Resolved;

            $title = $navEntry !== null ? $navEntry->label : $this->titleFromPuck($result->page_map[$sourceSlug] ?? [], $sourceSlug);
            $slug = $isHome ? '' : $this->slugFromTitle($title, $sourceSlug);

            // Slug rule 3 + 4: refuse `view*` and rename with a diagnostic.
            if ($slug !== '' && ($slug === 'view' || str_starts_with($slug, 'view/'))) {
                $renamed = 'page-'.$slug;
                $diagnostics[] = new Diagnostic(
                    severity: 'warning',
                    code: 'reserved_slug_renamed',
                    message: "Source slug `{$slug}` collides with the reserved `view*` route prefix. Renamed to `{$renamed}`.",
                    sourceUrl: new Optional,
                );
                $slug = $renamed;
            }

            // Slug rule 2: CI-unique. Collision → suffix.
            $canonical = strtolower(rtrim($slug));
            if (isset($usedSlugs[$canonical])) {
                $suffix = 2;
                do {
                    $candidate = $slug === '' ? "home-{$suffix}" : "{$slug}-{$suffix}";
                    $canonicalCandidate = strtolower($candidate);
                    $suffix++;
                } while (isset($usedSlugs[$canonicalCandidate]));
                $diagnostics[] = new Diagnostic(
                    severity: 'warning',
                    code: 'slug_collision_disambiguated',
                    message: "Slug `{$slug}` collided (CI); renamed to `{$candidate}`.",
                    sourceUrl: new Optional,
                );
                $slug = $candidate;
                $canonical = $canonicalCandidate;
            }
            $usedSlugs[$canonical] = true;

            $id = $this->pageId($isHome, $sourceSlug);
            $pageIdBySourceSlug[$sourceSlug] = $id;

            $pages[] = new Page(
                id: $id,
                slug: $slug,
                title: $title !== '' ? $title : ucwords(str_replace('-', ' ', $sourceSlug)),
                parentId: null, // M1 assumption: flat nav; nested tree deferred to broaden phase
                navOrder: $navEntry !== null ? $navEntry->order : $navOrderCounter++,
                showInNav: $showInNav,
                data: new PageData(
                    content: new DataCollection(Block::class, []),
                ),
            );
        }

        // Guarantee-check: exactly one page has slug="".
        $homeCount = 0;
        foreach ($pages as $p) {
            if ($p->slug === '') {
                $homeCount++;
            }
        }
        if ($homeCount !== 1) {
            $diagnostics[] = new Diagnostic(
                severity: 'error',
                code: 'homepage_shape_broken',
                message: "Expected exactly one page with slug=\"\"; got {$homeCount}. Envelope will fail contract validation.",
                sourceUrl: new Optional,
            );
        }

        return new PageTree(
            pages: $pages,
            pageIdBySourceSlug: $pageIdBySourceSlug,
            diagnostics: $diagnostics,
        );
    }

    /**
     * @return array{0: string|null, 1: Diagnostic|null} homepage source_slug + optional diagnostic if fallback used
     */
    private function pickHomepage(ConversionResult $result): array
    {
        // Contract Part II: pick the page the nav links to MOST often,
        // falling back to the shallowest URL. Our proxy: first
        // resolved nav item by ascending order.
        $resolvedByOrder = [];
        foreach ($result->nav as $item) {
            /** @var ResolvedNavItem $item */
            if ($item->status === ResolvedNavStatus::Resolved) {
                $resolvedByOrder[] = $item;
            }
        }
        usort($resolvedByOrder, static fn (ResolvedNavItem $a, ResolvedNavItem $b) => $a->order <=> $b->order);

        if ($resolvedByOrder !== []) {
            return [$resolvedByOrder[0]->page_slug, null];
        }

        // Fallback: first key in page_map.
        $firstSlug = array_key_first($result->page_map);
        if ($firstSlug === null) {
            return [null, new Diagnostic(
                severity: 'error',
                code: 'no_pages_to_map',
                message: 'ConversionResult carries no pages; cannot produce a valid payload (contract requires ≥1 page with slug="").',
                sourceUrl: new Optional,
            )];
        }

        return [$firstSlug, new Diagnostic(
            severity: 'warning',
            code: 'homepage_picked_by_fallback',
            message: "No Resolved nav entries; homepage set to first page_map key `{$firstSlug}`.",
            sourceUrl: new Optional,
        )];
    }

    /**
     * @param  DataCollection<int, ResolvedNavItem>  $nav
     * @return array<string, ResolvedNavItem> source_slug → nav entry
     */
    private function navIndex(DataCollection $nav): array
    {
        $ix = [];
        foreach ($nav as $item) {
            /** @var ResolvedNavItem $item */
            $ix[$item->page_slug] = $item;
        }

        return $ix;
    }

    /**
     * @param  array<string, ResolvedNavItem>  $navIndex
     * @return array<int, string>
     */
    private function orderedPageOrder(ConversionResult $result, array $navIndex): array
    {
        // Nav-resolved slugs first (in nav order); page_map slugs
        // NOT in nav next (in page_map insertion order — that's
        // typically depth-0 first anyway).
        $navSorted = [];
        foreach ($navIndex as $slug => $item) {
            if ($item->status === ResolvedNavStatus::Resolved && isset($result->page_map[$slug])) {
                $navSorted[$slug] = $item->order;
            }
        }
        asort($navSorted);

        $ordered = array_keys($navSorted);
        foreach (array_keys($result->page_map) as $slug) {
            if (! in_array($slug, $ordered, true)) {
                $ordered[] = $slug;
            }
        }

        return $ordered;
    }

    /**
     * Slice A: readable slugs. Prefer a slug derived from the page's
     * title over the opaque `page-<node_id>` source slug — the admin
     * reviewing the draft sees `/about` instead of `/page-7188115`,
     * and the contract's "faster to correct than to build from
     * scratch" framing depends on the human-touched surface being
     * legible.
     *
     * Fallback ladder:
     *   1. Title → slug (About Us → about-us, TBird News → tbird-news).
     *   2. If the title normalises to empty (empty title, all-punctuation
     *      title), fall back to the source-slug normaliser (page-N).
     *   3. Reserved-word matches (`view`, `view/*`) and CI-collisions
     *      are handled by the caller — this helper only produces the
     *      preferred slug shape.
     */
    private function slugFromTitle(string $title, string $sourceSlug): string
    {
        $fromTitle = $this->normaliseSlug($title);
        if ($fromTitle !== '') {
            return $fromTitle;
        }

        // Empty title or slug-of-punctuation-only. Fall back to the
        // pathological-case-safe source form.
        return $this->normaliseSlug($sourceSlug);
    }

    private function normaliseSlug(string $raw): string
    {
        $slug = trim($raw);
        // Strip common URL fluff.
        $slug = preg_replace('/\.(html?|php|aspx)$/i', '', $slug) ?? $slug;
        $slug = preg_replace('/^index\//i', '', $slug) ?? $slug;
        $slug = ltrim($slug, '/');
        $slug = rtrim($slug, '/');
        // Lowercase and hyphenate; our `page-<node_id>` inputs already
        // satisfy [a-z0-9-] so this is mostly a no-op for the
        // tbirdhoops fixture.
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9\/-]+/', '-', $slug) ?? $slug;
        $slug = preg_replace('/-+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');

        return $slug;
    }

    /**
     * @param  array<string, mixed>  $puckPage
     */
    private function titleFromPuck(array $puckPage, string $fallbackSourceSlug): string
    {
        $root = $puckPage['root'] ?? [];
        if (is_array($root) && is_string($root['title'] ?? null) && $root['title'] !== '') {
            return $root['title'];
        }

        return ucwords(str_replace('-', ' ', $fallbackSourceSlug));
    }

    private function pageId(bool $isHome, string $sourceSlug): string
    {
        // Contract Part II: "id is payload-local only. Your join key
        // for parentId. Never stored — we mint a UUID per page.
        // `home` is conventionally the homepage."
        return $isHome ? 'home' : $sourceSlug;
    }
}
