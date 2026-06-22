<?php

declare(strict_types=1);

namespace Tests\Support\Generate;

use App\Data\GlobalStyleBrief;
use App\Data\InventoryPage;
use App\Data\Ir;
use App\Data\IrBlock;
use App\Data\IrPassAgentResponse;
use App\Data\IrPassInput;
use App\Services\Generate\IrPassAgent;
use App\Services\Generate\PageSlug;
use Closure;
use Spatie\LaravelData\DataCollection;

// Offline fake. Records EVERY input the agent received (in call order) so
// tests can assert exactly what the orchestration sent — both the initial
// call and any targeted retry. A custom responder can branch on call
// index via captured state (`use (&$callIndex)`) to simulate the agent
// dropping pages on the first call and recovering on the second.
final class FakeIrPassAgent implements IrPassAgent
{
    /** @var array<int, IrPassInput>  every input the agent received, in call order */
    public array $allSeen = [];

    /** Convenience alias for the most recent input. */
    public ?IrPassInput $seen = null;

    public int $calls = 0;

    /** @var (Closure(IrPassInput): IrPassAgentResponse)|null */
    private ?Closure $responder = null;

    /**
     * @param  Closure(IrPassInput): IrPassAgentResponse  $responder
     */
    public function respondWith(Closure $responder): void
    {
        $this->responder = $responder;
    }

    public function run(IrPassInput $input): IrPassAgentResponse
    {
        $this->allSeen[] = $input;
        $this->seen = $input;
        $this->calls++;

        return ($this->responder ?? $this->defaultResponder())($input);
    }

    /**
     * Default: returns one IR per input keep_page with the PageSlug-derived
     * slug. Slug matching the orchestration's expected slug is critical —
     * a mismatch here would silently fail diff checks.
     *
     * @return Closure(IrPassInput): IrPassAgentResponse
     */
    private function defaultResponder(): Closure
    {
        return static function (IrPassInput $input): IrPassAgentResponse {
            /** @var array<int, Ir> $pages */
            $pages = [];
            /** @var array<int, InventoryPage> $keep */
            $keep = $input->keep_pages->items();
            foreach ($keep as $i => $page) {
                $pages[] = new Ir(
                    page_slug: PageSlug::of($page),
                    page_title: $page->label,
                    nav_order: $i,
                    blocks: new DataCollection(IrBlock::class, [
                        new IrBlock(
                            component_type: 'hero',
                            content_brief: "Welcome hero for {$page->label}",
                        ),
                        new IrBlock(
                            component_type: 'paragraph',
                            content_brief: "Intro paragraph for {$page->label}",
                        ),
                    ]),
                );
            }

            return new IrPassAgentResponse(
                style_brief: new GlobalStyleBrief(
                    brand_voice: 'fake voice — warm, community-focused',
                    palette: ['primary' => '#003366', 'secondary' => '#FFCC00'],
                    layout_conventions: ['fake convention 1', 'fake convention 2'],
                    nav: $input->nav,
                ),
                pages: new DataCollection(Ir::class, $pages),
            );
        };
    }
}
