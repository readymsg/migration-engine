<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\ConversionResult;
use App\Services\Conversion\ConversionResultStore;
use App\Services\Coverage\CoverageReport;
use App\Services\Coverage\PageMarkdownLoader;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

// Renders a per-conversion coverage report to
// `storage/app/coverage/{conversionId}.md`.
//
// The point of the artifact is to prove capture for the internal demo:
//   - what was rebuilt (CAPTURED)
//   - what was replaced by a live TeamLinkt block (SUPERSEDED)
//   - what fell through with no confident mapping (UNMAPPED)
//
// Inputs, in preference order:
//   1. --from-live         → ConversionResultStore (live cache)
//   2. --from-preview      → storage/app/public/preview/{id}.json
//                            (the deterministic replay the preview
//                            bundle already consumes — same JSON, same
//                            shape as ConversionResult::toArray())
//   3. --from-file=PATH    → any JSON file with the ConversionResult
//                            shape
//
// Source markdown is looked up from --scrapes-dir (default is the org
// scrapes dir once org_id is known from the ConversionResult).
final class GenerateCoverageReport extends Command
{
    protected $signature = 'migration:coverage
        {conversionId : conversion id or preview slug (e.g. "tbirdhoops")}
        {--from-live : load ConversionResult from the live ConversionResultStore}
        {--from-preview : load ConversionResult from storage/app/public/preview/{id}.json (default)}
        {--from-file= : load ConversionResult from an explicit JSON path}
        {--scrapes-dir= : override scrapes directory (default: storage/app/private/orgs/{org_id}/scrapes)}
        {--out= : override output path (default: storage/app/coverage/{conversionId}.md)}
    ';

    protected $description = 'Render a per-conversion coverage report (CAPTURED / SUPERSEDED / UNMAPPED) as markdown.';

    public function handle(CoverageReport $report, ConversionResultStore $store, PageMarkdownLoader $mdLoader): int
    {
        $conversionId = (string) $this->argument('conversionId');

        $result = $this->loadResult($conversionId, $store);
        if ($result === null) {
            return self::FAILURE;
        }

        $scrapesDir = $this->resolveScrapesDir($result->org_id);
        $pageMarkdown = $mdLoader->fromScrapesDir($scrapesDir, $result->page_map);

        $pageTitles = [];
        foreach ($result->page_map as $slug => $payload) {
            $pageTitles[$slug] = is_array($payload['root'] ?? null)
                && is_string($payload['root']['title'] ?? null)
                    ? $payload['root']['title']
                    : $slug;
        }

        // Extra pages (parked / unmapped source pages the pipeline set
        // aside). Currently sourced from ConversionResult.failures —
        // draft-landing surfaces PLAN parks that appeared in nav under
        // stage='draft-landing' with an explanatory reason. Report them
        // so the demo doesn't invisibly drop pages.
        $extraPages = [];
        foreach ($result->failures as $f) {
            if (isset($result->page_map[$f->page_slug])) {
                continue; // already in rebuilt set
            }
            $extraPages[] = [
                'page_title' => $f->page_title,
                'disposition' => $f->stage->value,
                'reason' => $f->reason,
                'url' => '',
            ];
        }

        $md = $report->render(
            pageMap: $result->page_map,
            pageTitles: $pageTitles,
            pageMarkdown: $pageMarkdown,
            scrubIssuesBySlug: $result->scrub_issues_by_slug,
            extraPages: $extraPages,
            meta: [
                'conversion_id' => $result->conversion_id,
                'org_id' => $result->org_id,
                'source_url' => $result->source_url,
                'status' => $result->status->value,
                // Palette provenance sidecars. Measured palette wins
                // precedence when non-empty; LLM shown for comparison.
                'brand_palette' => $result->brand->palette,
                'style_brief_palette' => $result->style_brief->palette,
            ],
        );

        $out = $this->resolveOutputPath($conversionId);
        $this->ensureDirectory(dirname($out));
        if (file_put_contents($out, $md) === false) {
            $this->error("Could not write report: {$out}");

            return self::FAILURE;
        }

        $this->info("Wrote coverage report to {$out}");
        $this->line(sprintf('  rebuilt pages: %d', count($result->page_map)));
        $this->line(sprintf('  scrape files loaded: %d / %d', count(array_filter($pageMarkdown, static fn (string $m) => $m !== '')), count($pageMarkdown)));
        $this->line(sprintf('  extra (parked / unmapped source) pages: %d', count($extraPages)));

        return self::SUCCESS;
    }

    private function loadResult(string $conversionId, ConversionResultStore $store): ?ConversionResult
    {
        $file = $this->option('from-file');
        if (is_string($file) && $file !== '') {
            return $this->loadResultFromFile($file);
        }
        if ($this->option('from-live')) {
            $live = $store->get($conversionId);
            if ($live === null) {
                $this->error("No live conversion in ConversionResultStore with id: {$conversionId}");

                return null;
            }

            return $live;
        }
        // Default: preview fixture on disk.
        $default = storage_path("app/public/preview/{$conversionId}.json");
        if (! is_file($default)) {
            $this->error("Preview fixture not found: {$default}");
            $this->line("Hint: run `php artisan engine:emit-preview-fixture --out={$default}`");
            $this->line('Or pass --from-live / --from-file=PATH.');

            return null;
        }

        return $this->loadResultFromFile($default);
    }

    private function loadResultFromFile(string $path): ?ConversionResult
    {
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->error("Could not read file: {$path}");

            return null;
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("File is not valid JSON: {$e->getMessage()}");

            return null;
        }

        return ConversionResult::from($decoded);
    }

    private function resolveScrapesDir(string $orgId): string
    {
        $override = $this->option('scrapes-dir');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        return storage_path("app/private/orgs/{$orgId}/scrapes");
    }

    private function resolveOutputPath(string $conversionId): string
    {
        $out = $this->option('out');
        if (is_string($out) && $out !== '') {
            return $out;
        }

        return storage_path("app/coverage/{$conversionId}.md");
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create directory: {$dir}");
        }
    }
}
