<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\BlockFillInput;
use App\Data\FilledBlock;
use App\Data\GlobalStyleBrief;
use App\Data\Ir;
use App\Data\IrBlock;
use App\Services\Generate\BlockFillAgent;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Throwable;

// DIAGNOSTIC TOOLING. Runs ONE Sonnet block-fill call against a hand-
// crafted BlockFillInput, dumping the resulting FilledPage. Used for
// targeted determinism + reproduction tests (e.g. "does Sonnet
// consistently emit blocks=[] for this rulebook-shaped page?") without
// re-running an entire site's pipeline.
//
// Cost: ONE Sonnet call (~$0.05-0.15 depending on body size). NOT a
// full site capture.
//
// Inputs:
//   --body-from   path to a scrape JSON on disk (e.g. storage/app/
//                 private/orgs/<org>/scrapes/<sha1>.json) — body_markdown
//                 and body_image_urls are pulled from this
//   --ir-from     path to a JSON file with a hand-crafted IR for the
//                 page (array of {component_type, content_brief,
//                 asset_refs}); also carries page_slug / page_title /
//                 nav_order
//   --style-brief-from  path to a BlockFillResult fixture; the
//                       style_brief is pulled from this so the call
//                       matches the original site's voice/palette/nav
//   --org-id      passed verbatim to BlockFillInput
//   --out         optional output path for the captured FilledPage JSON
final class ReplayPageBlockfill extends Command
{
    protected $signature = 'engine:replay-page-blockfill {--body-from=} {--ir-from=} {--style-brief-from=} {--org-id=} {--out=}';

    protected $description = 'Run ONE Sonnet block-fill call against a hand-crafted input; dump the FilledPage. Used for targeted determinism / reproduction tests.';

    public function handle(BlockFillAgent $agent): int
    {
        $bodyFrom = (string) ($this->option('body-from') ?? '');
        $irFrom = (string) ($this->option('ir-from') ?? '');
        $styleBriefFrom = (string) ($this->option('style-brief-from') ?? '');
        $orgId = (string) ($this->option('org-id') ?? '');
        $out = $this->option('out');
        $outPath = is_string($out) && $out !== '' ? $out : null;

        foreach (['body-from' => $bodyFrom, 'ir-from' => $irFrom, 'style-brief-from' => $styleBriefFrom, 'org-id' => $orgId] as $name => $value) {
            if ($value === '') {
                $this->error("--{$name} is required");

                return self::FAILURE;
            }
        }

        $this->info('=== Replay page block-fill ===');
        $this->line("body_from        : {$bodyFrom}");
        $this->line("ir_from          : {$irFrom}");
        $this->line("style_brief_from : {$styleBriefFrom}");
        $this->line("org_id           : {$orgId}");
        $this->newLine();

        try {
            $bodyData = $this->readJson($bodyFrom);
            $irData = $this->readJson($irFrom);
            $styleData = $this->readJson($styleBriefFrom);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $body = is_string($bodyData['markdown'] ?? null) ? $bodyData['markdown'] : '';
        $imageUrls = is_array($bodyData['image_urls'] ?? null) ? $bodyData['image_urls'] : [];

        $pageSlug = is_string($irData['page_slug'] ?? null) ? $irData['page_slug'] : 'page';
        $pageTitle = is_string($irData['page_title'] ?? null) ? $irData['page_title'] : 'Page';
        $navOrder = is_int($irData['nav_order'] ?? null) ? $irData['nav_order'] : 0;
        $rawIrBlocks = is_array($irData['blocks'] ?? null) ? $irData['blocks'] : [];

        /** @var array<int, IrBlock> $irBlocks */
        $irBlocks = [];
        foreach ($rawIrBlocks as $rb) {
            if (! is_array($rb)) {
                continue;
            }
            $irBlocks[] = new IrBlock(
                component_type: is_string($rb['component_type'] ?? null) ? $rb['component_type'] : '',
                content_brief: is_string($rb['content_brief'] ?? null) ? $rb['content_brief'] : '',
                asset_refs: is_array($rb['asset_refs'] ?? null) ? $rb['asset_refs'] : [],
            );
        }

        $ir = new Ir(
            page_slug: $pageSlug,
            page_title: $pageTitle,
            nav_order: $navOrder,
            blocks: new DataCollection(IrBlock::class, $irBlocks),
        );

        $styleBrief = GlobalStyleBrief::from($styleData['style_brief'] ?? []);

        $input = new BlockFillInput(
            org_id: $orgId,
            page_slug: $pageSlug,
            ir: $ir,
            style_brief: $styleBrief,
            body_markdown: $body,
            body_image_urls: $imageUrls,
        );

        $this->line(sprintf(
            'body length: %d chars, image_urls: %d, IR blocks: %d',
            strlen($body),
            count($imageUrls),
            count($irBlocks),
        ));
        $this->newLine();
        $this->info('Calling Sonnet... (one billable call)');

        $started = microtime(true);
        try {
            $filled = $agent->run($input);
        } catch (Throwable $e) {
            $this->error('Sonnet call threw: '.$e->getMessage());

            return self::FAILURE;
        }
        $elapsed = number_format(microtime(true) - $started, 1);

        $this->info("Sonnet call done in {$elapsed}s.");
        $this->line(sprintf(
            'FilledPage: slug=%s  title=%s  blocks=%d  confidence=%.2f',
            $filled->page_slug,
            $filled->page_title,
            $filled->blocks->count(),
            $filled->confidence,
        ));
        $this->line('self_assessment:');
        $this->line('  '.$filled->self_assessment);
        $this->newLine();

        if ($filled->blocks->count() === 0) {
            $this->warn('blocks=[] — empty FilledPage. Sonnet emitted zero blocks.');
        } else {
            $this->info('=== Blocks ===');
            /** @var array<int, FilledBlock> $blocks */
            $blocks = $filled->blocks->items();
            foreach ($blocks as $i => $b) {
                $props = $b->props;
                $hint = $props['heading'] ?? $props['text'] ?? $props['title'] ?? $props['body'] ?? '';
                if (is_string($hint)) {
                    $hint = substr($hint, 0, 80);
                } else {
                    $hint = '(non-string preview)';
                }
                $this->line(sprintf('  [%d] %-12s %s', $i, $b->component_type, $hint));
            }
        }

        if ($outPath !== null) {
            $dir = dirname($outPath);
            if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
                throw new RuntimeException("Could not create directory: {$dir}");
            }
            try {
                $json = json_encode(
                    $filled->toArray(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );
            } catch (JsonException $e) {
                throw new RuntimeException("Could not encode FilledPage: {$e->getMessage()}");
            }
            if (file_put_contents($outPath, $json.PHP_EOL) === false) {
                throw new RuntimeException("Could not write: {$outPath}");
            }
            $this->newLine();
            $this->line("wrote {$outPath}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Could not read: {$path}");
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Not valid JSON: {$path} — {$e->getMessage()}");
        }

        return $decoded;
    }
}
