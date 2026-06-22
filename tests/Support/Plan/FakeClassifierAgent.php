<?php

declare(strict_types=1);

namespace Tests\Support\Plan;

use App\Data\ClassificationResponse;
use App\Data\DecisionAction;
use App\Data\InventoryPage;
use App\Services\Plan\ClassifierAgent;
use Closure;

// Deterministic offline fake. Records every page it sees so a test can prove
// the planner never sent external / dynamic / unknown nodes through it.
//
// A custom responder closure can be set per-test to drive specific scenarios
// (low-confidence drops, merges, etc.). Default response is keep@0.85.
final class FakeClassifierAgent implements ClassifierAgent
{
    /** @var array<int, InventoryPage>  pages routed to the LLM, in call order */
    public array $seen = [];

    /** @var array<int, array<int, InventoryPage>>  one row per batch call */
    public array $batches = [];

    /** @var (Closure(InventoryPage): ClassificationResponse)|null */
    private ?Closure $responder = null;

    /**
     * @param  Closure(InventoryPage): ClassificationResponse  $responder
     */
    public function respondWith(Closure $responder): void
    {
        $this->responder = $responder;
    }

    public function classifyBatch(array $batch, string $brandVoiceHint = ''): array
    {
        $this->batches[] = $batch;

        $out = [];
        foreach ($batch as $i => $page) {
            $this->seen[] = $page;
            $out[$i] = ($this->responder ?? $this->defaultResponder())($page);
        }

        return $out;
    }

    /**
     * @return Closure(InventoryPage): ClassificationResponse
     */
    private function defaultResponder(): Closure
    {
        return static fn (InventoryPage $page): ClassificationResponse => new ClassificationResponse(
            action: DecisionAction::Keep,
            confidence: 0.85,
            reason: 'fake-default-keep',
        );
    }
}
