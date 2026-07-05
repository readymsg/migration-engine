<?php

declare(strict_types=1);

namespace Tests\Support\Generate;

use App\Data\GlobalStyleBrief;
use App\Data\IrBriefDeriverInput;
use App\Services\Generate\IrBriefDeriverAgent;
use Closure;
use Throwable;

// Offline fake for the brief-deriver. Records every input and returns a
// deterministic style brief; tests can install a custom responder
// (including one that throws) to exercise the brief-failure-fallback
// path in IrPass.
final class FakeIrBriefDeriverAgent implements IrBriefDeriverAgent
{
    /** @var array<int, IrBriefDeriverInput>  every input seen, in call order */
    public array $allSeen = [];

    public ?IrBriefDeriverInput $seen = null;

    public int $calls = 0;

    /** @var (Closure(IrBriefDeriverInput): GlobalStyleBrief)|null */
    private ?Closure $responder = null;

    /** @var Throwable|null  if set, the NEXT call throws this exception (one-shot) */
    private ?Throwable $throws = null;

    /**
     * @param  Closure(IrBriefDeriverInput): GlobalStyleBrief  $responder
     */
    public function respondWith(Closure $responder): void
    {
        $this->responder = $responder;
    }

    public function throwOnNextCall(Throwable $e): void
    {
        $this->throws = $e;
    }

    public function run(IrBriefDeriverInput $input): GlobalStyleBrief
    {
        $this->allSeen[] = $input;
        $this->seen = $input;
        $this->calls++;

        if ($this->throws !== null) {
            $e = $this->throws;
            $this->throws = null;
            throw $e;
        }

        return ($this->responder ?? $this->defaultResponder())($input);
    }

    /**
     * @return Closure(IrBriefDeriverInput): GlobalStyleBrief
     */
    private function defaultResponder(): Closure
    {
        return static fn (IrBriefDeriverInput $input): GlobalStyleBrief => new GlobalStyleBrief(
            brand_voice: 'fake voice — warm, community-focused',
            palette: ['primary' => '#003366', 'secondary' => '#FFCC00'],
            layout_conventions: ['fake convention 1', 'fake convention 2'],
            nav: $input->nav,
        );
    }
}
