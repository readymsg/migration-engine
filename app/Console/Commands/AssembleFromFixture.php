<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\AssemblyBlockIssue;
use App\Data\AssemblyFailure;
use App\Data\AssemblyResult;
use App\Data\BlockFillResult;
use App\Data\PuckOutput;
use App\Services\Generate\Assembler;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

// Deterministic replay: read a saved BlockFillResult JSON fixture and
// run the Assembler against it. NO LLM, NO network. Prints the Puck
// JSON per page plus every AssemblyBlockIssue, so a reviewer can see
// exactly what the deterministic assembler did to real captured
// Sonnet output before any of it ships into createDraftSite().
//
// Default input is the durable tbirdhoops fixture written by
// engine:capture-tbirdhoops-block-fill.
final class AssembleFromFixture extends Command
{
    protected $signature = 'engine:assemble-from-fixture {--in= : Input fixture path} {--page= : Only print this page slug} {--summary : Only print the reconciliation summary}';

    protected $description = 'Replay the deterministic Assembler against a saved BlockFillResult fixture.';

    public function handle(Assembler $assembler): int
    {
        $in = (string) ($this->option('in') ?? base_path('tests/Fixtures/blockfill/tbirdhoops.json'));
        $pageFilter = $this->option('page');
        $pageFilterValue = is_string($pageFilter) && $pageFilter !== '' ? $pageFilter : null;
        $summaryOnly = (bool) $this->option('summary');

        if (! is_file($in)) {
            $this->error("Fixture not found: {$in}");

            return self::FAILURE;
        }

        $raw = file_get_contents($in);
        if ($raw === false) {
            throw new RuntimeException("Could not read fixture: {$in}");
        }
        try {
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("Fixture is not valid JSON: {$e->getMessage()}");

            return self::FAILURE;
        }
        if (! is_array($decoded)) {
            $this->error('Fixture root is not an object');

            return self::FAILURE;
        }

        /** @var array<string, mixed> $decoded */
        $blockFill = BlockFillResult::from($decoded);

        $result = $assembler->run($blockFill);

        $this->info('=== Assembler replay ===');
        $this->line("input            : {$in}");
        $this->line("blockfill.status : {$blockFill->status->value}");
        $this->line("blockfill.pages  : {$blockFill->pages->count()}");
        $this->line("blockfill.fails  : {$blockFill->failures->count()}");
        $this->newLine();
        $this->info('=== Assembly result ===');
        $this->line("assembly.status  : {$result->status->value}");
        $this->line("assembly.pages   : {$result->pages->count()}");
        $this->line("assembly.fails   : {$result->failures->count()}");
        $totalIssues = 0;
        foreach ($result->block_issues_by_slug as $slug => $issues) {
            $totalIssues += count($issues);
        }
        $this->line("assembly.issues  : {$totalIssues} across ".count($result->block_issues_by_slug).' page(s)');
        $this->newLine();

        if ($summaryOnly) {
            return self::SUCCESS;
        }

        $this->printFailures($result);
        $this->printIssues($result);
        $this->printPuck($result, $pageFilterValue);

        return self::SUCCESS;
    }

    private function printFailures(AssemblyResult $result): void
    {
        if ($result->failures->count() === 0) {
            return;
        }
        $this->info('=== AssemblyFailures ===');
        /** @var array<int, AssemblyFailure> $failures */
        $failures = $result->failures->items();
        foreach ($failures as $f) {
            $this->line(sprintf(
                '- %s (%s): %s',
                $f->page_slug,
                $f->page_title,
                $f->reason,
            ));
        }
        $this->newLine();
    }

    private function printIssues(AssemblyResult $result): void
    {
        if ($result->block_issues_by_slug === []) {
            $this->info('=== AssemblyBlockIssues ===');
            $this->line('(none — no substitutions or drops on any block)');
            $this->newLine();

            return;
        }
        $this->info('=== AssemblyBlockIssues ===');
        foreach ($result->block_issues_by_slug as $slug => $issues) {
            $this->line(sprintf('* %s — %d issue(s):', $slug, count($issues)));
            foreach ($issues as $i) {
                /** @var AssemblyBlockIssue $i */
                $this->line(sprintf(
                    '    [block %d] %s (%s) @ %s',
                    $i->block_index,
                    $i->coercion->value,
                    $i->component_type,
                    $i->path ?? '(top-level)',
                ));
                $this->line(sprintf('      %s', $i->reason));
            }
        }
        $this->newLine();
    }

    private function printPuck(AssemblyResult $result, ?string $pageFilter): void
    {
        $this->info('=== PuckOutput per page ===');
        /** @var array<int, PuckOutput> $pages */
        $pages = $result->pages->items();
        foreach ($pages as $page) {
            if ($pageFilter !== null && $page->page_slug !== $pageFilter) {
                continue;
            }
            $this->line(sprintf('--- %s (%s) — %d top-level blocks ---', $page->page_slug, $page->root['title'] ?? '', count($page->content)));
            $json = json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                $this->line($json);
            }
            $this->newLine();
        }
    }
}
