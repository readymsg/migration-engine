<?php

declare(strict_types=1);

namespace App\Services\Extract;

use App\Data\AssetRef;
use App\Data\Brand;
use App\Data\ContentExtractionFailure;
use App\Data\ContentRef;
use App\Data\Manifest;
use App\Data\NavNode;
use App\Data\SiteStructure;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Throwable;

// Stage 1 INGEST for SportsEngine — written against the real rootNav shape
// recon'd in tests/Fixtures/rootnav/real/. Highlights:
//
//   - v1 scope: SITE REBUILD only — structure + brand + content. Provisioning
//     (teams/divisions/admins, team logos) is explicitly out of scope; the
//     Provisioning DTO is kept as scaffolding but never populated.
//   - rootNav is per-node: /page/nav/<id> returns one node with its
//     parent / siblings / children. The full tree is built by BFS.
//   - node_type taxonomy (Page | Calendar | NewsNode | other | null) is the
//     real classification — kind is derived from it. No invented flags.
//   - Brand comes from the homepage HTML, never from rootNav.
//   - Theme-agnostic: never reads the inline `var rootNav` blob. itasca
//     and waterworld both go through the API the same way.
//   - Captures the post-redirect URL in `source_url`.
final class SportNginExtractor implements Extractor
{
    private const MAX_DEPTH = 5;

    public function __construct(
        private readonly HtmlFetcher $htmlFetcher,
        private readonly RootNavFetcher $rootNavFetcher,
        private readonly FirecrawlClient $firecrawl,
        private readonly AssetUploader $uploader,
        private readonly BrandExtractor $brandExtractor,
        private readonly SeCdnRehoster $cdnRehoster,
    ) {}

    public function extract(string $url): Manifest
    {
        $homepage = $this->htmlFetcher->fetch($url);
        $orgUrl = $this->originOf($homepage->final_url);

        $siteId = $this->extractSiteId($homepage->html);
        if ($siteId === null) {
            throw new RuntimeException("Could not find a SportsEngine site_id in homepage HTML: {$url}");
        }
        $orgId = "ngin-{$siteId}";

        $startNodeId = $this->resolveStartNode($orgUrl, $homepage->html);
        if ($startNodeId === null) {
            throw new RuntimeException("Could not find a usable page_node_id in homepage HTML: {$url}");
        }

        $structure = $this->buildStructure($orgUrl, $startNodeId);

        // Brand upload failure is a soft signal, not a fatal abort — mirrors
        // how the scrape path and the CDN rehoster handle disk/source faults.
        // A broken brand asset shouldn't kill the whole extraction before any
        // page bodies are captured; fall back to the existing 'flag' (no-logo)
        // brand and surface 'brand_upload_failed: <reason>' on the Manifest so
        // a reviewer can see the gap.
        $brandUploadError = null;
        try {
            $brand = $this->brandExtractor->extract($homepage->html, $orgId, $this->uploader);
        } catch (Throwable $e) {
            $brandUploadError = $e->getMessage();
            $brand = new Brand(
                logo_source: 'flag',
                logo_asset_ref: null,
                palette: [],
                voice_hint: null,
            );
        }

        [$contentRefs, $assetRefs, $contentFailures, $cdnFound, $cdnRehosted] = $this->scrapeContent($structure, $orgUrl, $orgId);

        if ($brand->logo_asset_ref !== null) {
            $assetRefs[] = new AssetRef(
                s3_key: $brand->logo_asset_ref,
                mime_type: 'image/*',
                source_url: null,
            );
        }

        return new Manifest(
            source_url: $homepage->final_url,
            org_id: $orgId,
            structure: $structure,
            provisioning: null, // v1 scope cut — site rebuild only
            brand: $brand,
            content_refs: new DataCollection(ContentRef::class, $contentRefs),
            asset_refs: new DataCollection(AssetRef::class, $assetRefs),
            confidence: $this->confidence($structure, $brand),
            flags: $this->flags($homepage, $structure, $brand, $contentFailures, $cdnFound, $cdnRehosted, $brandUploadError),
            content_failures: new DataCollection(ContentExtractionFailure::class, $contentFailures),
            cdn_assets_found: $cdnFound,
            cdn_assets_rehosted: $cdnRehosted,
        );
    }

    private function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || ! is_string($parts['scheme']) || ! is_string($parts['host'])) {
            throw new RuntimeException("Unparseable URL: {$url}");
        }

        return $parts['scheme'].'://'.$parts['host'];
    }

    private function extractSiteId(string $html): ?int
    {
        // Universal across themes: every SE page has a `site_files/<id>/favicon.ico` link.
        if (preg_match('#site_files/(\d+)/#', $html, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Heuristic: prefer itasca's explicit `var currentId = 'page_node_<id>'`,
     * else try every distinct `page_node_<id>` ref in the HTML until one
     * returns a successful nav fetch. Some ids (e.g. the site's root parent
     * page) return 401 — we skip them and try the next.
     */
    private function resolveStartNode(string $orgUrl, string $html): ?int
    {
        if (preg_match('#var\s+currentId\s*=\s*[\'"]page_node_(\d+)[\'"]#', $html, $m) === 1) {
            return (int) $m[1];
        }

        preg_match_all('#page_node_(\d+)#', $html, $matches);
        $ids = array_values(array_unique(array_map('intval', $matches[1])));
        foreach ($ids as $id) {
            try {
                $this->rootNavFetcher->fetchNode($orgUrl, $id);

                return $id;
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function buildStructure(string $orgUrl, int $startNodeId): SiteStructure
    {
        $home = $this->rootNavFetcher->fetchNode($orgUrl, $startNodeId);
        /** @var array<int, mixed> $rawSiblings */
        $rawSiblings = is_array($home['siblings'] ?? null) ? $home['siblings'] : [];

        $nodes = [];
        foreach ($rawSiblings as $sibling) {
            if (! is_array($sibling)) {
                continue;
            }
            $nodes[] = $this->expandNode($orgUrl, $sibling, 1);
        }

        $total = 0;
        $this->walkNav($nodes, function () use (&$total): void {
            $total++;
        });

        return new SiteStructure(
            nav: new DataCollection(NavNode::class, $nodes),
            pages_total: $total,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function expandNode(string $orgUrl, array $raw, int $depth): NavNode
    {
        $rawId = $raw['id'] ?? null;
        $nodeId = $this->extractNodeId($rawId);
        $nodeType = is_string($raw['node_type'] ?? null) ? $raw['node_type'] : null;
        $hasChild = (int) ($raw['has_child'] ?? 0);
        [$kind, $externalSubtype] = $this->classify($nodeType, $rawId);

        $children = [];
        if ($hasChild > 0 && $nodeId !== null && $depth < self::MAX_DEPTH) {
            try {
                $childPayload = $this->rootNavFetcher->fetchNode($orgUrl, $nodeId);
                /** @var array<int, mixed> $rawChildren */
                $rawChildren = is_array($childPayload['children'] ?? null) ? $childPayload['children'] : [];
                foreach ($rawChildren as $child) {
                    if (! is_array($child)) {
                        continue;
                    }
                    $children[] = $this->expandNode($orgUrl, $child, $depth + 1);
                }
            } catch (Throwable) {
                // Couldn't expand this node — leaves children empty; has_child > 0
                // with empty children is the signal a re-fetch could fill this in later.
            }
        }

        return new NavNode(
            label: $this->stringOr($raw['name'] ?? null, ''),
            url: $this->stringOrNull($raw['url'] ?? null),
            kind: $kind,
            children: new DataCollection(NavNode::class, $children),
            node_type: $nodeType,
            page_node_id: $nodeId,
            external_subtype: $externalSubtype,
        );
    }

    private function extractNodeId(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw;
        }
        if (! is_string($raw)) {
            return null;
        }
        if (preg_match('#^page_node_(\d+)$#', $raw, $m) === 1) {
            return (int) $m[1];
        }
        if (ctype_digit($raw)) {
            return (int) $raw;
        }

        return null;
    }

    /**
     * Map a rootNav node to (kind, external_subtype). External shapes recon'd
     * after dumping real manifests: SE injects `LinkNode` siblings (external
     * shop / external resource link) and a hardcoded `id: "toolsLink"` sibling
     * pointing at SE's own Dibs volunteer-scheduling tool. Both stay in the
     * tree so the page-list reads true to the source, but classified
     * 'external' so PLAN doesn't treat them as content pages.
     *
     * @return array{0: string, 1: ?string}
     */
    private function classify(?string $nodeType, mixed $rawId): array
    {
        if ($nodeType === 'LinkNode') {
            return ['external', 'external_link'];
        }
        if ($rawId === 'toolsLink') {
            return ['external', 'se_tool'];
        }
        // Catch-all for SE's other "not really a page" siblings: null node_type
        // paired with a non-page_node id. Kept external, subtype unknown.
        if ($nodeType === null && is_string($rawId) && preg_match('#^page_node_\d+$#', $rawId) !== 1) {
            return ['external', null];
        }

        // SE's `Instance` node_types encode the league hierarchy: a site's
        // league→division→team subtree comes back as LeagueInstance →
        // DivisionInstance → TeamInstance. Each maps to its own kind so PLAN
        // can route them to the right PlatformBlockType (Teams / Divisions /
        // Team) WITHOUT the parent subsuming the children. Unknown Instance
        // types (TournamentInstance, SeasonInstance, ...) still bucket into
        // 'dynamic_other' — the vestigial-Dynamic + visible-failure path —
        // rather than silently mis-mapping to something wrong.
        $kind = match (true) {
            $nodeType === 'Page' => 'page',
            $nodeType === 'Calendar' => 'dynamic_calendar',
            $nodeType === 'NewsNode' => 'dynamic_news',
            $nodeType === 'LeagueInstance' => 'dynamic_league',
            $nodeType === 'DivisionInstance' => 'dynamic_division',
            $nodeType === 'TeamInstance' => 'dynamic_team',
            is_string($nodeType) && $nodeType !== '' => 'dynamic_other',
            default => 'unknown',
        };

        return [$kind, null];
    }

    /**
     * @param  array<int, NavNode>  $nodes
     * @param  callable(NavNode):void  $visitor
     */
    private function walkNav(array $nodes, callable $visitor): void
    {
        foreach ($nodes as $node) {
            $visitor($node);
            /** @var array<int, NavNode> $children */
            $children = $node->children->items();
            $this->walkNav($children, $visitor);
        }
    }

    /**
     * Captures body content for every kind=page nav node with a URL.
     * Reconciliation invariant: total kind=page-with-url nodes == count
     * of returned contentRefs + count of contentFailures. NEVER silently
     * drop a page — the same faithful-rebuild rule we apply to the IR
     * pass and the planner.
     *
     * Also sums SE-CDN re-host counts across pages so the Manifest can
     * surface a soft signal when assets were lost.
     *
     * @return array{0: array<int, ContentRef>, 1: array<int, AssetRef>, 2: array<int, ContentExtractionFailure>, 3: int, 4: int}
     */
    private function scrapeContent(SiteStructure $structure, string $orgUrl, string $orgId): array
    {
        /** @var array<int, ContentRef> $contentRefs */
        $contentRefs = [];
        /** @var array<int, AssetRef> $assetRefs */
        $assetRefs = [];
        /** @var array<int, ContentExtractionFailure> $contentFailures */
        $contentFailures = [];
        $cdnFound = 0;
        $cdnRehosted = 0;

        /** @var array<int, NavNode> $rootNodes */
        $rootNodes = $structure->nav->items();
        $this->walkNav($rootNodes, function (NavNode $node) use ($orgUrl, $orgId, &$contentRefs, &$assetRefs, &$contentFailures, &$cdnFound, &$cdnRehosted): void {
            if ($node->kind !== 'page' || $node->url === null) {
                return;
            }
            $absoluteUrl = $this->absoluteUrl($orgUrl, $node->url);

            try {
                $scrape = $this->firecrawl->scrape($absoluteUrl);
            } catch (Throwable $e) {
                $contentFailures[] = new ContentExtractionFailure(
                    url: $absoluteUrl,
                    page_title: $node->label,
                    page_node_id: $node->page_node_id,
                    reason: 'firecrawl_threw: '.$e->getMessage(),
                );

                return;
            }

            if ($scrape === null) {
                $contentFailures[] = new ContentExtractionFailure(
                    url: $absoluteUrl,
                    page_title: $node->label,
                    page_node_id: $node->page_node_id,
                    reason: 'firecrawl_returned_null',
                );

                return;
            }

            // Persist the raw scrape JSON to S3 — debugging + downstream
            // block-fill consume from here.
            try {
                $assetRef = $this->uploader->putContent(
                    (string) json_encode($scrape->toArray(), JSON_THROW_ON_ERROR),
                    'application/json',
                    $orgId,
                    'scrapes',
                    sprintf('%s.json', sha1($absoluteUrl)),
                );
            } catch (Throwable $e) {
                $contentFailures[] = new ContentExtractionFailure(
                    url: $absoluteUrl,
                    page_title: $node->label,
                    page_node_id: $node->page_node_id,
                    reason: 'scrape_upload_failed: '.$e->getMessage(),
                );

                return;
            }
            $assetRefs[] = $assetRef;

            $contentRefs[] = new ContentRef(
                url: $absoluteUrl,
                scrape_ref: $assetRef->s3_key,
                title: $scrape->title !== '' ? $scrape->title : null,
                nav_path: [$node->label],
            );

            // Re-host every SE-CDN asset URL referenced from the body so
            // the rebuilt site has zero live SportsEngine dependency.
            // Per-asset failures are swallowed inside the rehoster (body
            // is still captured); the found-vs-rehosted counts surface on
            // the Manifest so a partial loss isn't invisible.
            $rehost = $this->cdnRehoster->rehost($scrape, $orgId);
            foreach ($rehost['refs'] as $cdnRef) {
                $assetRefs[] = $cdnRef;
            }
            $cdnFound += $rehost['found'];
            $cdnRehosted += $rehost['rehosted'];
        });

        return [$contentRefs, $assetRefs, $contentFailures, $cdnFound, $cdnRehosted];
    }

    private function absoluteUrl(string $orgUrl, string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim($orgUrl, '/').'/'.ltrim($url, '/');
    }

    private function confidence(SiteStructure $structure, Brand $brand): float
    {
        // v1 site-rebuild scoring: only structure + brand contribute. No
        // provisioning signal. Stage 4 (SCORE & LOG) will replace this with
        // the richer rubric (logo found + content density + Puck validated).
        $score = 0.0;
        if ($structure->pages_total > 0) {
            $score += 0.5;
        }
        if ($brand->logo_source !== 'flag') {
            $score += 0.5;
        }

        return round($score, 2);
    }

    /**
     * @param  array<int, ContentExtractionFailure>  $contentFailures
     * @return array<int, string>
     */
    private function flags(HtmlFetchResult $homepage, SiteStructure $structure, Brand $brand, array $contentFailures, int $cdnFound, int $cdnRehosted, ?string $brandUploadError): array
    {
        $flags = [];
        if ($homepage->requested_url !== $homepage->final_url) {
            $flags[] = "redirected: {$homepage->requested_url} -> {$homepage->final_url}";
        }
        if ($structure->pages_total === 0) {
            $flags[] = 'empty_nav';
        }
        if ($brand->logo_source === 'flag') {
            $flags[] = 'logo_fallback_flagged';
        }
        if ($brandUploadError !== null) {
            $flags[] = 'brand_upload_failed: '.$brandUploadError;
        }
        if ($contentFailures !== []) {
            $flags[] = 'content_extraction_partial: '.count($contentFailures).' page(s) failed';
        }
        if ($cdnRehosted < $cdnFound) {
            $flags[] = "cdn_rehost_partial: {$cdnRehosted}/{$cdnFound} assets re-hosted";
        }

        return $flags;
    }

    private function stringOr(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
