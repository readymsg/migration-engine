<?php

declare(strict_types=1);

namespace App\Http\Controllers\Conversion;

use App\Data\ConversionPipelineStage;
use App\Http\Controllers\Controller;
use App\Jobs\ConversionJob;
use App\Services\Conversion\ConversionDedupeStore;
use App\Services\Conversion\ConversionResultStore;
use App\Services\Conversion\ConversionStatusStore;
use App\Services\Generate\BlockFillResultStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Trigger + status + result endpoints for the step-6 conversion
// pipeline. Three routes, all under the EnsureDemoToken middleware
// (+ throttle on the POST).
//
//   POST /api/conversions
//   GET  /api/conversions/{id}/status
//   GET  /api/conversions/{id}
//
// DEDUPE ON REFRESH is load-bearing: same (token, url) POSTed again
// within 10 min returns the EXISTING conversion_id (200), not a new
// one (202). A nervous demo watcher hitting refresh must NOT trigger
// a second $2-6 Sonnet conversion. See ConversionDedupeStore.
final class ConversionController extends Controller
{
    public function __construct(
        private readonly ConversionDedupeStore $dedupeStore,
        private readonly ConversionStatusStore $statusStore,
        private readonly ConversionResultStore $resultStore,
        private readonly BlockFillResultStore $blockFillResultStore,
    ) {}

    public function trigger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'url', 'max:2048'],
        ]);
        /** @var string $url */
        $url = $validated['url'];

        $token = (string) $request->header('X-Demo-Token', '');

        $freshId = 'conv-'.Str::random(16);
        $conversionId = $this->dedupeStore->registerOrGetExisting($token, $url, $freshId);

        $isNew = $conversionId === $freshId;

        if ($isNew) {
            // First trigger for this (token, url) — begin status + queue
            // the job. Order matters: status begin() BEFORE the job
            // dispatch so a very-fast worker can't beat us to advance().
            $this->statusStore->begin($conversionId, $url);
            ConversionJob::dispatch($conversionId, $url);
        }

        return response()->json([
            'conversion_id' => $conversionId,
            'status_url' => route('conversions.status', $conversionId),
            'result_url' => route('conversions.show', $conversionId),
            'preview_url' => url('/preview/'.$conversionId),
            'deduped' => ! $isNew,
        ], $isNew ? 202 : 200);
    }

    public function status(string $conversionId): JsonResponse
    {
        $snapshot = $this->statusStore->get($conversionId);
        if ($snapshot === null) {
            return response()->json(['error' => 'unknown conversion_id'], 404);
        }

        $body = $snapshot->toArray();
        $body['final_status'] = $snapshot->finalStatus();

        // Compute block-fill progress on the fly from the block-fill
        // stores. Cheap read + no worker-side status dependency (see
        // ConversionStatusStore docblock).
        if ($snapshot->stage === ConversionPipelineStage::BlockFill) {
            $progress = $this->computeBlockFillProgress($conversionId);
            if ($progress !== null) {
                $body['block_fill_progress'] = $progress;
            }
        }

        return response()->json($body);
    }

    public function show(string $conversionId): JsonResponse
    {
        $result = $this->resultStore->get($conversionId);
        if ($result === null) {
            // Distinguish "unknown" (no such conversion) from
            // "not-ready-yet" (conversion exists but hasn't finished
            // finalize). The status store answers that.
            $snapshot = $this->statusStore->get($conversionId);
            if ($snapshot === null) {
                return response()->json(['error' => 'unknown conversion_id'], 404);
            }

            return response()->json([
                'error' => 'not ready',
                'stage' => $snapshot->stage->value,
                'status_url' => route('conversions.status', $conversionId),
            ], 409);
        }

        return response()->json($result->toArray());
    }

    /**
     * @return ?array{done: int, total: int}
     */
    private function computeBlockFillProgress(string $conversionId): ?array
    {
        $state = $this->blockFillResultStore->getReconcileState($conversionId);
        if ($state === null) {
            return null;
        }

        $total = count($state->expected_slugs);
        $done = 0;
        foreach ($state->expected_slugs as $slug) {
            if ($this->blockFillResultStore->getFilledPage($conversionId, $slug) !== null
                || $this->blockFillResultStore->getFailure($conversionId, $slug) !== null) {
                $done++;
            }
        }

        return ['done' => $done, 'total' => $total];
    }
}
