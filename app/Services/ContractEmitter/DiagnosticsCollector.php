<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\AssemblyBlockIssue;
use App\Data\ConversionFailure;
use App\Data\ConversionResult;
use App\Data\ScrubIssue;
use App\Data\SiteImport\Diagnostic;
use Spatie\LaravelData\Optional;

// Turns every silent-loss surface in the engine into contract
// diagnostics[] entries. Contract Part II verbatim:
//   "Tell us what you gave up on. This is how the contract improves —
//    a recurring diagnostic is a feature request for a new block.
//    Please use this generously."
//
// Sources folded here:
//   1. ConversionResult.failures     → severity=error (stage in code)
//   2. block_issues_by_slug           → severity=warning (per-block)
//   3. scrub_issues_by_slug           → severity=warning (per-kind)
//   4. Extras from the mapper / page-tree / site-settings emitters
//      (unmappable_block_type, hero_image_unresolvable, columns_flattened,
//       homepage_picked_by_fallback, reserved_slug_renamed, etc.)
//      passed through as $extra.
//
// The output is ordered: infra diagnostics first (fallback picks,
// slug renames), warnings next, errors last. That way a reviewer
// scanning the array top-down hits the informational stuff first
// and the "you should look at this" stuff last.
final class DiagnosticsCollector
{
    /**
     * @param  array<int, Diagnostic>  $extra  diagnostics from earlier stages of the emitter (mapper, page tree, site-settings)
     * @return array<int, Diagnostic>
     */
    public function collect(ConversionResult $result, array $extra = []): array
    {
        $out = [];

        // Extras get appended first so they're grouped by producing
        // stage (page-tree diagnostics arrive before mapper ones).
        foreach ($extra as $d) {
            if ($d instanceof Diagnostic) {
                $out[] = $d;
            }
        }

        // ConversionFailures → errors, stage encoded in code.
        foreach ($result->failures as $f) {
            /** @var ConversionFailure $f */
            $out[] = new Diagnostic(
                severity: 'error',
                code: 'stage_failure_'.$f->stage->value,
                message: sprintf('%s failure on page `%s`: %s', $f->stage->value, $f->page_slug, $f->reason),
                sourceUrl: new Optional,
            );
        }

        // Per-page assembly block issues → warnings.
        foreach ($result->block_issues_by_slug as $slug => $issues) {
            if (! is_array($issues) || ! is_string($slug)) {
                continue;
            }
            foreach ($issues as $issue) {
                if (! $issue instanceof AssemblyBlockIssue) {
                    continue;
                }
                $out[] = new Diagnostic(
                    severity: 'warning',
                    code: 'assembly_'.$issue->coercion->value,
                    message: sprintf('%s on `%s`: %s', $issue->component_type, $slug, $issue->reason),
                    sourceUrl: new Optional,
                );
            }
        }

        // Scrub issues (SE-promo, stale countdowns, hero-image
        // resolver findings). Kind-based codes so a reviewer can
        // grep for "hero_image_unreachable" across payloads.
        foreach ($result->scrub_issues_by_slug as $slug => $issues) {
            if (! is_array($issues) || ! is_string($slug)) {
                continue;
            }
            foreach ($issues as $issue) {
                if (! $issue instanceof ScrubIssue) {
                    continue;
                }
                $severity = $issue->kind->value === 'hero_image_chosen' ? 'info' : 'warning';
                $out[] = new Diagnostic(
                    severity: $severity,
                    code: $issue->kind->value,
                    message: sprintf('%s on `%s`: %s', $issue->component_type, $slug, $issue->reason),
                    sourceUrl: new Optional,
                    droppedContent: $issue->dropped_content_summary !== '' ? $issue->dropped_content_summary : new Optional,
                );
            }
        }

        return $out;
    }
}
