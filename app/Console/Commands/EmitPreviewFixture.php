<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\AssemblyResult;
use App\Data\AssetRef;
use App\Data\BlockFillResult;
use App\Data\ConversionResult;
use App\Data\Manifest;
use App\Services\Coverage\PageMarkdownLoader;
use App\Services\Extract\LogoPaletteExtractor;
use App\Services\Generate\Assembler;
use App\Services\Generate\AssetUrlRewriter;
use App\Services\Generate\BlockCoercer;
use App\Services\Generate\ContentLoader;
use App\Services\Generate\DraftLanding;
use App\Services\Generate\GalleryFiller;
use App\Services\Generate\HeroImageResolver;
use App\Services\Generate\PlatformBlockRenderer;
use App\Services\Generate\SePlatformBlockScrubber;
use App\Services\Plan\RootNavPlanner;
use App\Services\Plan\SePlatformContentDetector;
use App\Services\Schema\DefaultPuckComponentSchema;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Tests\Support\Extract\FakeAssetUploader;
use Tests\Support\Generate\RecordingProductClient;
use Tests\Support\Plan\FakeClassifierAgent;
use Tests\Support\Plan\RealManifests;

// THROWAWAY (BUILD.md step 7). Emits the ConversionResult JSON the
// preview bundle renders against, by replaying the exact pipeline
// DraftLandingFixtureReplayTest exercises:
//
//   real Manifest (tbirdhoops rootnav fixtures, no HTTP)
//     → PLAN (FakeClassifierAgent — deterministic, offline)
//   tbirdhoops BlockFillResult fixture (tests/Fixtures/blockfill/)
//     → Assembler
//   SitePlan + Manifest
//     → PlatformBlockRenderer
//   all of the above
//     → DraftLanding (RecordingProductClient — no real product call)
//   → ConversionResult JSON → storage/app/public/preview/<slug>.json
//
// NO LLM. NO network. Cost: 0. Determinism: full. Re-run any time the
// upstream BlockFillResult fixture or any deterministic stage changes;
// the bundle's input shape stays stable (mirrors the eventual
// GET /api/demo/{id}/site that step 6 will produce).
//
// Dev-only: depends on Tests\Support helpers (autoload-dev). Not
// available under composer install --no-dev. Same posture as the
// throwaway Demo/Preview namespace — deleted at graduation alongside
// the rest of the preview slice.
final class EmitPreviewFixture extends Command
{
    protected $signature = 'engine:emit-preview-fixture {--out= : Output path (default: storage/app/public/preview/tbirdhoops.json)}';

    protected $description = 'Replay the deterministic pipeline and write a ConversionResult JSON the preview bundle consumes.';

    public function handle(): int
    {
        $output = (string) ($this->option('out')
            ?? storage_path('app/public/preview/tbirdhoops.json'));

        $this->info('=== Preview fixture emit ===');
        $this->line("output : {$output}");
        $this->line('source : tbirdhoops rootnav fixtures + tests/Fixtures/blockfill/tbirdhoops.json');
        $this->line('cost   : 0 (deterministic; no LLM, no network)');
        $this->newLine();

        // Pass the LogoPaletteExtractor so BrandExtractor fetches the
        // logo bytes and measures the actual palette. Costs one HTTP
        // hit to the SE CDN per fixture regen. Offline tests keep the
        // default null and skip the fetch.
        $manifest = RealManifests::tbirdhoops(new LogoPaletteExtractor);
        $this->line(sprintf(
            '[1/5] Manifest: content_refs=%d  asset_refs=%d',
            $manifest->content_refs->count(),
            $manifest->asset_refs->count(),
        ));

        $schema = new DefaultPuckComponentSchema;
        $planner = new RootNavPlanner(
            new FakeClassifierAgent,
            new ContentLoader(disk: 'local'),
            new SePlatformContentDetector,
        );
        $plan = $planner->plan($manifest);
        $this->line(sprintf(
            '[2/5] PLAN:     kept_pages=%d  nav=%d  ledger=%d',
            $plan->kept_pages->count(),
            $plan->nav->count(),
            $plan->ledger->entries->count(),
        ));

        $blockFill = $this->loadBlockFillFixture();
        $this->line(sprintf(
            '[3/5] BlockFill (fixture): pages=%d  failures=%d',
            $blockFill->pages->count(),
            $blockFill->failures->count(),
        ));

        $assembly = (new Assembler(new BlockCoercer($schema)))->run($blockFill);
        $this->line(sprintf(
            '[4/5] Assembler: status=%s  pages=%d  failures=%d',
            $assembly->status->value,
            $assembly->pages->count(),
            $assembly->failures->count(),
        ));

        // Deterministic SE-platform block scrub. Same wiring position
        // as CaptureLive: Assembler → Scrubber → Platform → DraftLanding.
        // Without this, the preview JSON would still show the tbirdhoops
        // Home SE-promo ButtonGroup + 3 stale-countdown Cards — the ad
        // this scrubber exists to remove.
        $assembly = (new SePlatformBlockScrubber)->run($assembly);
        $this->line(sprintf(
            '       scrub: pages_touched=%d  blocks_scrubbed=%d',
            count($assembly->scrub_issues_by_slug),
            array_sum(array_map('count', $assembly->scrub_issues_by_slug)),
        ));

        // Deterministic gallery back-fill — repairs block-fill's silent
        // gallery truncation (see GalleryFiller docblock). Uses the
        // real tbirdhoops scrapes on disk since the offline Manifest
        // has empty content_refs.
        $mdLoader = new PageMarkdownLoader;
        $slugToMd = $mdLoader->fromScrapesDir(
            storage_path("app/private/orgs/{$manifest->org_id}/scrapes"),
            $this->pageMapFromAssembly($assembly),
        );
        $assembly = (new GalleryFiller)->run($assembly, $slugToMd);
        $galleryEvents = 0;
        foreach ($assembly->scrub_issues_by_slug as $pageIssues) {
            foreach ($pageIssues as $issue) {
                if (str_starts_with($issue->kind->value, 'gallery_')) {
                    $galleryEvents++;
                }
            }
        }
        $this->line(sprintf('       gallery-fill events: %d', $galleryEvents));

        // Deliberate hero-image resolver — deterministic pass that
        // picks banner-shape URLs over block-fill's first-image
        // fallback. Uses source markdown as the candidate pool.
        // MUST run before AssetUrlRewriter (needs original CDN URLs).
        $assembly = (new HeroImageResolver)->run($assembly, $slugToMd);
        $heroEvents = 0;
        foreach ($assembly->scrub_issues_by_slug as $pageIssues) {
            foreach ($pageIssues as $issue) {
                if ($issue->kind->value === 'hero_image_chosen') {
                    $heroEvents++;
                }
            }
        }
        $this->line(sprintf('       hero-image decisions: %d', $heroEvents));

        // Populate synthetic asset_refs from every SE-CDN URL that
        // appears in the assembled Puck output. The offline Manifest
        // (via FakeFirecrawlClient) has no content_refs so the live
        // pipeline's SeCdnRehoster never ran; without this step
        // AssetUrlRewriter would flag every URL as
        // AssetRehostMissing. Uses FakeAssetUploader::putFromUrl for
        // deterministic-fake s3://fake/ngin-63620/content_assets/…
        // keys — same shape production would emit. Demo-only sim of
        // what a live INGEST would have produced.
        $manifest = $this->synthesiseAssetRefs($manifest, $assembly);
        $this->line(sprintf(
            '       synth asset_refs: %d (from cdn*.sportngin.com URLs in assembled Puck)',
            $manifest->asset_refs->count(),
        ));

        // Deterministic SE-CDN URL rewrite — swap every live cdn*
        // .sportngin.com URL in the assembled Puck for its rehosted
        // S3 key. See AssetUrlRewriter docblock. In prod this runs in
        // FinalizeConversionJob with a real Manifest.
        $assembly = (new AssetUrlRewriter)->run($assembly, $manifest);
        [$rewritten, $missing] = $this->countAssetEvents($assembly);
        $this->line(sprintf('       asset-url rewrites: %d, live SE URLs left: %d', $rewritten, $missing));

        $platform = (new PlatformBlockRenderer($schema))->run($plan, $manifest);
        $this->line(sprintf(
            '[5/5] Platform:  status=%s  pages=%d  failures=%d',
            $platform->status->value,
            $platform->pages->count(),
            $platform->failures->count(),
        ));

        $client = new RecordingProductClient;
        $client->returns('TBIRD_PREVIEW', 'https://teamlinkt.test/drafts/TBIRD_PREVIEW');
        $result = (new DraftLanding($client))->run(
            conversionId: 'tbirdhoops-preview',
            plan: $plan,
            assembly: $assembly,
            platform: $platform,
            manifest: $manifest,
        );

        $this->newLine();
        $this->info('=== ConversionResult ===');
        $this->line("status          : {$result->status->value}");
        $this->line('page_map keys   : '.implode(', ', array_keys($result->page_map)));
        $this->line("nav entries     : {$result->nav->count()}");
        $this->line("failures        : {$result->failures->count()}");

        $this->writeFixture($result, $output);
        $this->newLine();
        $this->info("Wrote preview fixture to {$output}");
        $this->line('Browse: php artisan serve  +  npm run dev  →  http://127.0.0.1:8000/preview/tbirdhoops');

        return self::SUCCESS;
    }

    // Demo-only simulation of what a live INGEST would have produced.
    // Walks the assembled Puck output, collects every cdn*.sportngin.com
    // asset URL, and constructs a deterministic-fake AssetRef via
    // FakeAssetUploader::putFromUrl (same shape / hash / prefix a real
    // rehost would produce, minus real S3 I/O).
    private function synthesiseAssetRefs(Manifest $manifest, AssemblyResult $assembly): Manifest
    {
        $urls = $this->collectSeCdnUrls($assembly);
        if ($urls === []) {
            return $manifest;
        }
        $uploader = new FakeAssetUploader;
        /** @var array<int, AssetRef> $refs */
        $refs = $manifest->asset_refs->items();
        foreach ($urls as $url) {
            $refs[] = $uploader->putFromUrl($url, $manifest->org_id, 'content_assets');
        }

        return new Manifest(
            source_url: $manifest->source_url,
            org_id: $manifest->org_id,
            structure: $manifest->structure,
            provisioning: $manifest->provisioning,
            brand: $manifest->brand,
            content_refs: $manifest->content_refs,
            asset_refs: new DataCollection(AssetRef::class, $refs),
            confidence: $manifest->confidence,
            flags: $manifest->flags,
            content_failures: $manifest->content_failures,
            cdn_assets_found: $manifest->cdn_assets_found + count($urls),
            cdn_assets_rehosted: $manifest->cdn_assets_rehosted + count($urls),
        );
    }

    /**
     * @return array<int, string> unique SE-CDN URLs found in the assembled Puck output
     */
    private function collectSeCdnUrls(AssemblyResult $assembly): array
    {
        /** @var array<string, true> $seen */
        $seen = [];
        foreach ($assembly->pages->items() as $page) {
            foreach ($page->content as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $this->collectFromValue($block, $seen);
            }
        }

        return array_keys($seen);
    }

    /**
     * @param  array<string, true>  $seen
     */
    private function collectFromValue(mixed $value, array &$seen): void
    {
        if (is_string($value)) {
            if (preg_match_all('#https?://[^\s"\'<>()\[\]]+#i', $value, $m) > 0) {
                foreach ($m[0] as $u) {
                    $u = rtrim($u, '.,;:!?');
                    $host = parse_url($u, PHP_URL_HOST);
                    if (! is_string($host)) {
                        continue;
                    }
                    $host = strtolower($host);
                    $matches = $host === 'sportngin.com'
                        || str_ends_with($host, '.sportngin.com')
                        || $host === 'assets.ngin.com';
                    if ($matches) {
                        $seen[$u] = true;
                    }
                }
            }

            return;
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                $this->collectFromValue($v, $seen);
            }
        }
    }

    /**
     * @return array{0: int, 1: int} [rewrites, missing]
     */
    private function countAssetEvents(AssemblyResult $assembly): array
    {
        $rewrites = 0;
        $missing = 0;
        foreach ($assembly->scrub_issues_by_slug as $issues) {
            foreach ($issues as $issue) {
                if ($issue->kind->value === 'asset_url_rewritten') {
                    $rewrites++;
                } elseif ($issue->kind->value === 'asset_rehost_missing') {
                    $missing++;
                }
            }
        }

        return [$rewrites, $missing];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function pageMapFromAssembly(AssemblyResult $assembly): array
    {
        /** @var array<string, array<string, mixed>> $out */
        $out = [];
        foreach ($assembly->pages->items() as $page) {
            $out[$page->page_slug] = [
                'content' => $page->content,
                'root' => $page->root,
                'zones' => $page->zones,
            ];
        }

        return $out;
    }

    private function loadBlockFillFixture(): BlockFillResult
    {
        $path = base_path('tests/Fixtures/blockfill/tbirdhoops.json');
        if (! is_file($path)) {
            throw new RuntimeException("BlockFillResult fixture not found: {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Could not read fixture: {$path}");
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Fixture is not valid JSON: {$e->getMessage()}");
        }

        return BlockFillResult::from($decoded);
    }

    private function writeFixture(ConversionResult $result, string $output): void
    {
        $dir = dirname($output);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create directory: {$dir}");
        }

        try {
            $json = json_encode(
                $result->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new RuntimeException("Could not encode ConversionResult: {$e->getMessage()}");
        }
        if (file_put_contents($output, $json.PHP_EOL) === false) {
            throw new RuntimeException("Could not write fixture: {$output}");
        }
    }
}
