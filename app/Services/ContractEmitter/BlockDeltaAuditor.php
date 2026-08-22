<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\SiteImport\Diagnostic;
use App\Data\SiteImport\Envelope;
use Spatie\LaravelData\Optional;

// Automatic reconciliation: every source content block must be
// accounted for in the output. The user's ask verbatim:
//   "The emitter should assert it. Any block that can't be
//    attributed is itself a diagnostic. I shouldn't have to ask
//    for this reconciliation each batch."
//
// Approach: the mapper records every transformation to a
// MapperAudit (cause, inputBlocks, outputBlocks). The auditor
// reconciles three counts:
//
//   1. INPUT: content blocks in the source page_map (top-level
//      + Columns.children[], with ButtonGroup counted as N-buttons).
//   2. AUDITED-INPUT: sum of inputBlocks reported by mapper calls.
//   3. OUTPUT: contract blocks in the finished envelope (deep
//      walk into slot children too).
//
// Reconciliation passes when:
//   - INPUT == AUDITED-INPUT (every source block was seen by
//     the mapper)
//   - OUTPUT == sum of audited outputBlocks (mapper accounted
//     for every emitted block)
//
// A mismatch on EITHER count fires a `block_delta_unaccounted`
// error diagnostic. The info-level `block_delta_summary` always
// emits so a reviewer can see the accounting even when clean.
//
// This is the first line of defence against the class of
// silent-loss defects that hit us twice in the last two batches
// (37% of blocks vanished without accounting). If this ever fires
// an unattributed-delta error, the fix is either a new mapper
// transformation without an audit call, or a real silent-drop
// path (a block vanished without any record).
final class BlockDeltaAuditor
{
    /**
     * @param  array<string, array<string, mixed>>  $sourcePageMap
     */
    public function audit(array $sourcePageMap, Envelope $envelope, MapperAudit $mapperAudit): BlockDeltaReport
    {
        $inputSource = $this->countInputContentBlocks($sourcePageMap);
        $inputAudited = $mapperAudit->totalInputBlocks();
        $outputEnvelope = $this->countOutputBlocksDeep($envelope);
        $outputAudited = $mapperAudit->totalOutputBlocks();

        return new BlockDeltaReport(
            blocksIn: $inputSource,
            blocksOut: $outputEnvelope,
            inputAudited: $inputAudited,
            outputAudited: $outputAudited,
            actualDelta: $outputEnvelope - $inputSource,
            inputMismatch: $inputSource - $inputAudited,
            outputMismatch: $outputEnvelope - $outputAudited,
            attributions: $mapperAudit->summary(),
        );
    }

    /**
     * @return array<int, Diagnostic>
     */
    public function toDiagnostics(BlockDeltaReport $report): array
    {
        $diagnostics = [];
        $attributionLines = [];
        foreach ($report->attributions as $cause => $info) {
            $sign = $info['delta'] >= 0 ? '+' : '';
            $attributionLines[] = sprintf(
                '%s: %d× (in %d, out %d, %s%d)',
                $cause,
                $info['occurrences'],
                $info['inputBlocks'],
                $info['outputBlocks'],
                $sign,
                $info['delta'],
            );
        }
        $summary = sprintf(
            'Block-delta audit: %d source blocks in, %d contract blocks out (delta %+d). Mapper attributions: %s. Input mismatch: %d, output mismatch: %d.',
            $report->blocksIn,
            $report->blocksOut,
            $report->actualDelta,
            $attributionLines === [] ? '(none)' : implode(' · ', $attributionLines),
            $report->inputMismatch,
            $report->outputMismatch,
        );
        $diagnostics[] = new Diagnostic(
            severity: 'info',
            code: 'block_delta_summary',
            message: $summary,
            sourceUrl: new Optional,
        );

        if (! $report->isReconciled()) {
            $diagnostics[] = new Diagnostic(
                severity: 'error',
                code: 'block_delta_unaccounted',
                message: sprintf(
                    'Block-delta reconciliation FAILED: input source count %d vs audited %d (diff %d); output envelope count %d vs audited %d (diff %d). Either a mapper transformation was added without an audit call, or a real silent-drop path opened.',
                    $report->blocksIn,
                    $report->inputAudited,
                    $report->inputMismatch,
                    $report->blocksOut,
                    $report->outputAudited,
                    $report->outputMismatch,
                ),
                sourceUrl: new Optional,
            );
        }

        return $diagnostics;
    }

    /**
     * @param  array<string, array<string, mixed>>  $pageMap
     */
    private function countInputContentBlocks(array $pageMap): int
    {
        $count = 0;
        foreach ($pageMap as $page) {
            if (! is_array($page)) {
                continue;
            }
            $content = $page['content'] ?? [];
            if (! is_array($content)) {
                continue;
            }
            $count += $this->countInputContentBlocksInList($content);
        }

        return $count;
    }

    /**
     * @param  array<int, mixed>  $blocks
     */
    private function countInputContentBlocksInList(array $blocks): int
    {
        $count = 0;
        foreach ($blocks as $b) {
            if (! is_array($b) || ! is_string($b['type'] ?? null)) {
                continue;
            }
            if ($b['type'] === 'Columns') {
                // Columns wrappers are consumed; recurse into children.
                $columns = is_array($b['props']['columns'] ?? null) ? $b['props']['columns'] : [];
                foreach ($columns as $col) {
                    if (! is_array($col)) {
                        continue;
                    }
                    $children = is_array($col['children'] ?? null) ? $col['children'] : [];
                    $count += $this->countInputContentBlocksInList($children);
                }

                continue;
            }
            if ($b['type'] === 'ButtonGroup') {
                $buttons = is_array($b['props']['buttons'] ?? null) ? $b['props']['buttons'] : [];
                $count += count($buttons);

                continue;
            }
            $count++;
        }

        return $count;
    }

    private function countOutputBlocksDeep(Envelope $envelope): int
    {
        $count = 0;
        foreach ($envelope->pages as $page) {
            foreach ($page->data->content as $block) {
                $count += $this->countBlockDeep($block->toArray());
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $blockArray
     */
    private function countBlockDeep(array $blockArray): int
    {
        $count = 1;
        $props = is_array($blockArray['props'] ?? null) ? $blockArray['props'] : [];
        foreach ($props as $value) {
            if (! is_array($value)) {
                continue;
            }
            foreach ($value as $child) {
                if (is_array($child) && is_string($child['type'] ?? null)) {
                    $count += $this->countBlockDeep($child);
                }
            }
        }

        return $count;
    }
}
