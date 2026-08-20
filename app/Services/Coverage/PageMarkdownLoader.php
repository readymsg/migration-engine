<?php

declare(strict_types=1);

namespace App\Services\Coverage;

use App\Data\ContentRef;
use App\Data\InventoryPage;
use App\Data\Manifest;
use App\Data\SitePlan;
use App\Services\Generate\ContentLoader;
use App\Services\Generate\PageSlug;
use Illuminate\Support\Str;
use JsonException;

// Build a page_slug → source-markdown map for the coverage report AND
// the deterministic GalleryFiller. Two source paths:
//
//   1. fromManifest — production path. Given a Manifest + SitePlan +
//      ContentLoader, correlate each kept content page's URL to its
//      ContentRef and load via the configured scrapes disk. The
//      production pipeline (FinalizeConversionJob) has all three.
//
//   2. fromScrapesDir — demo path. Given the ConversionResult page_map
//      + a scrapes directory on the local filesystem, correlate by
//      (a) page node id in `/page/show/{id}-` URL patterns, (b) fuzzy
//      title match, (c) URL last-segment vs slugified page title. Used
//      by EmitPreviewFixture and the migration:coverage command — both
//      have preview fixtures on disk but no live Manifest.
//
// Same shape returned by both: array<string, string> where keys are
// page_slug (matching page_map keys). Missing scrape → empty string
// entry (loader errs on the side of a present-but-empty value so
// callers see EVERY slug in the result).
final class PageMarkdownLoader
{
    /**
     * @return array<string, string> page_slug → markdown (empty string when unmatched)
     */
    public function fromManifest(Manifest $manifest, SitePlan $plan, ContentLoader $loader): array
    {
        /** @var array<string, ContentRef> $byUrl */
        $byUrl = [];
        foreach ($manifest->content_refs->items() as $ref) {
            $byUrl[$ref->url] = $ref;
        }

        /** @var array<string, string> $out */
        $out = [];
        foreach ($plan->kept_pages->items() as $page) {
            if (! $page instanceof InventoryPage) {
                continue;
            }
            if ($page->kind !== 'page' || $page->url === null || $page->url === '') {
                continue;
            }
            $slug = PageSlug::of($page);
            $ref = $byUrl[$page->url] ?? null;
            if ($ref === null) {
                $out[$slug] = '';

                continue;
            }
            $loaded = $loader->load($ref);
            $out[$slug] = $loaded !== null ? $loaded->markdown : '';
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $pageMap  ConversionResult.page_map (values may include root.title)
     * @return array<string, string> page_slug → markdown (empty string when unmatched)
     */
    public function fromScrapesDir(string $scrapesDir, array $pageMap): array
    {
        $scrapes = $this->loadScrapes($scrapesDir);
        /** @var array<string, string> $out */
        $out = [];
        foreach ($pageMap as $slug => $payload) {
            $title = $this->titleFromPayload($payload, $slug);
            $out[$slug] = $this->findMarkdown($slug, $title, $scrapes);
        }

        return $out;
    }

    /**
     * @return array<int, array{url: string, title: string, markdown: string}>
     */
    private function loadScrapes(string $scrapesDir): array
    {
        /** @var array<int, array{url: string, title: string, markdown: string}> $out */
        $out = [];
        if (! is_dir($scrapesDir)) {
            return $out;
        }
        foreach (glob($scrapesDir.'/*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);
            if (! is_string($raw)) {
                continue;
            }
            try {
                /** @var mixed $j */
                $j = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (! is_array($j)) {
                continue;
            }
            $url = is_string($j['url'] ?? null) ? $j['url'] : '';
            $title = is_string($j['title'] ?? null) ? $j['title'] : '';
            $md = is_string($j['markdown'] ?? null) ? $j['markdown'] : '';
            if ($url === '' || $md === '') {
                continue;
            }
            $out[] = ['url' => $url, 'title' => $title, 'markdown' => $md];
        }

        return $out;
    }

    /**
     * @param  array<int, array{url: string, title: string, markdown: string}>  $scrapes
     */
    private function findMarkdown(string $slug, string $title, array $scrapes): string
    {
        if (preg_match('/^page-(\d+)$/', $slug, $m) === 1) {
            $needle = '/page/show/'.$m[1].'-';
            foreach ($scrapes as $s) {
                if (str_contains($s['url'], $needle)) {
                    return $s['markdown'];
                }
            }
        }

        $titleLower = mb_strtolower(trim($title));
        if ($titleLower !== '') {
            foreach ($scrapes as $s) {
                $scrapeTitleLower = mb_strtolower(trim($s['title']));
                if ($scrapeTitleLower === '') {
                    continue;
                }
                if ($scrapeTitleLower === $titleLower) {
                    return $s['markdown'];
                }
                if (str_contains($scrapeTitleLower, $titleLower) || str_contains($titleLower, $scrapeTitleLower)) {
                    return $s['markdown'];
                }
            }
        }

        $slugFromTitle = Str::slug($title);
        if ($slugFromTitle !== '') {
            foreach ($scrapes as $s) {
                $path = parse_url($s['url'], PHP_URL_PATH);
                if (! is_string($path)) {
                    continue;
                }
                $segments = array_values(array_filter(explode('/', $path), static fn (string $p) => $p !== ''));
                $last = end($segments);
                if (! is_string($last)) {
                    continue;
                }
                if (str_contains(mb_strtolower($last), $slugFromTitle)
                    || str_contains($slugFromTitle, mb_strtolower(str_replace('-', '', $last)))) {
                    return $s['markdown'];
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function titleFromPayload(array $payload, string $slug): string
    {
        $root = is_array($payload['root'] ?? null) ? $payload['root'] : [];
        $t = $root['title'] ?? null;

        return is_string($t) && $t !== '' ? $t : $slug;
    }
}
