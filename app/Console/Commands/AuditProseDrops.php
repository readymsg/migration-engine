<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\ConversionResult;
use App\Services\Coverage\CoverageReconciler;
use App\Services\Coverage\PageMarkdownLoader;
use App\Services\Coverage\SourceElementCounter;
use Illuminate\Console\Command;

// One-off diagnostic — dump every prose-DROPPED element on tbirdhoops
// with its full text, page, and whether a same-page ScrubIssue's
// dropped_content_summary covers it. Used to establish the evidence
// baseline for Slice 2 (matching source elements to scrub summaries).
final class AuditProseDrops extends Command
{
    protected $signature = 'coverage:audit-prose-drops
        {--in= : ConversionResult JSON (default: storage/app/public/preview/tbirdhoops.json)}';

    protected $description = 'Dump every prose DROPPED element with same-page ScrubIssue match evidence.';

    public function handle(
        SourceElementCounter $counter,
        CoverageReconciler $reconciler,
        PageMarkdownLoader $mdLoader,
    ): int {
        $path = (string) ($this->option('in') ?? storage_path('app/public/preview/tbirdhoops.json'));
        if (! is_file($path)) {
            $this->error("Not found: {$path}");

            return self::FAILURE;
        }
        $raw = (string) file_get_contents($path);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $result = ConversionResult::from($decoded);

        $slugToMd = $mdLoader->fromScrapesDir(
            storage_path("app/private/orgs/{$result->org_id}/scrapes"),
            $result->page_map,
        );

        $total = 0;
        $matchScrub = 0;
        /** @var array<string, array<int, string>> $otherByPattern */
        $otherByPattern = [];
        $rows = [];

        foreach ($result->page_map as $slug => $payload) {
            $md = $slugToMd[$slug] ?? '';
            if ($md === '') {
                continue;
            }
            $c = $counter->count($md);
            $reconciled = $reconciler->reconcile(
                elements: $c['elements'],
                puckPayload: $payload,
                scrubs: $result->scrub_issues_by_slug[$slug] ?? [],
                sourceOrigin: $result->source_url,
            );

            // Concatenate all scrub summaries on this page.
            $scrubSummary = '';
            foreach ($result->scrub_issues_by_slug[$slug] ?? [] as $s) {
                $scrubSummary .= "\n".(is_array($s)
                    ? ($s['dropped_content_summary'] ?? '')
                    : $s->dropped_content_summary);
            }
            $scrubLower = mb_strtolower($scrubSummary);

            $title = is_array($payload['root'] ?? null) && is_string($payload['root']['title'] ?? null)
                ? $payload['root']['title']
                : $slug;

            foreach ($reconciled as $r) {
                if ($r->disposition !== 'dropped' || $r->source->kind !== 'prose') {
                    continue;
                }
                $total++;
                $text = $r->source->content;
                $textLower = mb_strtolower(trim($text));
                $len = mb_strlen($textLower);
                // str_contains(anything, '') is TRUE — guard the empty
                // scrub-summary case explicitly.
                $matches = false;
                $scrubTrim = trim($scrubLower);
                if ($len >= 8 && $scrubTrim !== '') {
                    if (str_contains($scrubLower, $textLower)) {
                        $matches = true;
                    } elseif (mb_strlen($scrubTrim) >= 8 && str_contains($textLower, $scrubTrim)) {
                        $matches = true;
                    }
                }
                if ($matches) {
                    $matchScrub++;
                    $bucket = '[scrub]';
                } else {
                    $bucket = '[real ]';
                    $trim = trim($text);
                    $key = 'other';
                    if (preg_match('/^(Days?|Hours?|Minutes?|Seconds?)\**\d*$/i', $trim)) {
                        $key = 'countdown-unit-label';
                    } elseif (preg_match('/^\*\*\d+\*\*$|^\d+$/', $trim)) {
                        $key = 'countdown-number-alone';
                    } elseif (preg_match('/@[a-z0-9.-]+\.[a-z]{2,}/i', $trim)) {
                        $key = 'contains-email';
                    } elseif (preg_match('/\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b/', $trim)) {
                        $key = 'contains-phone';
                    } elseif ($len <= 20) {
                        $key = 'short (≤20 chars)';
                    } elseif ($len <= 60) {
                        $key = 'medium (21-60 chars)';
                    } else {
                        $key = 'long (>60 chars)';
                    }
                    $otherByPattern[$key][] = $trim;
                }
                $rows[] = compact('title', 'slug', 'bucket', 'len', 'text');
            }
        }

        // Emit
        $this->info('=== Prose DROPPED audit ===');
        $this->newLine();
        foreach ($rows as $row) {
            $this->line(sprintf(
                '%s %s (%s) len=%3d  %s',
                $row['bucket'],
                $row['title'],
                $row['slug'],
                $row['len'],
                mb_substr($row['text'], 0, 140),
            ));
        }

        $this->newLine();
        $this->info('=== Summary ===');
        $this->line("Total prose DROPPED: {$total}");
        $this->line("  matches same-page ScrubIssue: {$matchScrub}");
        $this->line('  no ScrubIssue match:          '.($total - $matchScrub));
        $this->newLine();
        $this->info('=== Non-scrub drops, grouped by pattern ===');
        foreach ($otherByPattern as $pattern => $items) {
            $this->line(sprintf('%s: %d', $pattern, count($items)));
            foreach (array_slice($items, 0, 5) as $x) {
                $this->line('  - '.mb_substr($x, 0, 120));
            }
            if (count($items) > 5) {
                $this->line(sprintf('  ... (%d more)', count($items) - 5));
            }
        }

        return self::SUCCESS;
    }
}
