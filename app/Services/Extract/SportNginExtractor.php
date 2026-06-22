<?php

declare(strict_types=1);

namespace App\Services\Extract;

use App\Data\AssetRef;
use App\Data\Brand;
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
        $brand = $this->brandExtractor->extract($homepage->html, $orgId, $this->uploader);

        [$contentRefs, $assetRefs] = $this->scrapeContent($structure, $orgUrl, $orgId);

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
            flags: $this->flags($homepage, $structure, $brand),
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
        $nodeId = $this->extractNodeId($raw['id'] ?? null);
        $nodeType = is_string($raw['node_type'] ?? null) ? $raw['node_type'] : null;
        $hasChild = (int) ($raw['has_child'] ?? 0);

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
            kind: $this->classifyKind($nodeType),
            children: new DataCollection(NavNode::class, $children),
            node_type: $nodeType,
            page_node_id: $nodeId,
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

    private function classifyKind(?string $nodeType): string
    {
        return match (true) {
            $nodeType === 'Page' => 'page',
            $nodeType === 'Calendar' => 'dynamic_calendar',
            $nodeType === 'NewsNode' => 'dynamic_news',
            is_string($nodeType) && $nodeType !== '' => 'dynamic_other',
            default => 'unknown',
        };
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
     * @return array{0: array<int, ContentRef>, 1: array<int, AssetRef>}
     */
    private function scrapeContent(SiteStructure $structure, string $orgUrl, string $orgId): array
    {
        /** @var array<int, ContentRef> $contentRefs */
        $contentRefs = [];
        /** @var array<int, AssetRef> $assetRefs */
        $assetRefs = [];

        /** @var array<int, NavNode> $rootNodes */
        $rootNodes = $structure->nav->items();
        $this->walkNav($rootNodes, function (NavNode $node) use ($orgUrl, $orgId, &$contentRefs, &$assetRefs): void {
            if ($node->kind !== 'page' || $node->url === null) {
                return;
            }
            $absoluteUrl = $this->absoluteUrl($orgUrl, $node->url);

            // BUILD.md: submit + poll. v1 calls them back-to-back; the queue
            // moves the "submit a batch, poll all" loop into stage 3.
            $jobId = $this->firecrawl->submit($absoluteUrl);
            $scrape = $this->firecrawl->poll($jobId);
            if ($scrape === null) {
                return;
            }

            $assetRef = $this->uploader->putContent(
                (string) json_encode($scrape->toArray(), JSON_THROW_ON_ERROR),
                'application/json',
                $orgId,
                'scrapes',
                sprintf('%s.json', sha1($absoluteUrl)),
            );
            $assetRefs[] = $assetRef;

            $contentRefs[] = new ContentRef(
                url: $absoluteUrl,
                scrape_ref: $assetRef->s3_key,
                title: $scrape->title !== '' ? $scrape->title : null,
                nav_path: [$node->label],
            );
        });

        return [$contentRefs, $assetRefs];
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
     * @return array<int, string>
     */
    private function flags(HtmlFetchResult $homepage, SiteStructure $structure, Brand $brand): array
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
