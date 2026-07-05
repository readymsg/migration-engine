<?php

declare(strict_types=1);

namespace Tests\Support\Generate;

use App\Data\InventoryPage;
use App\Data\Ir;
use App\Data\IrBlock;
use App\Data\IrChunkDesignerInput;
use App\Data\IrChunkDesignerResponse;
use App\Services\Generate\IrChunkDesignerAgent;
use App\Services\Generate\PageSlug;
use Closure;
use Spatie\LaravelData\DataCollection;

// Offline fake for the chunk-designer. Records every input (so tests
// can assert chunk-partitioning + the locked brief is what IrPass
// constructed) and returns one Ir per input chunk_page by default.
// Custom responder supports the silent-drop + targeted-retry tests
// (e.g., drop page X on call 1, return page X on the retry call).
final class FakeIrChunkDesignerAgent implements IrChunkDesignerAgent
{
    /** @var array<int, IrChunkDesignerInput>  every input seen, in call order — including retries */
    public array $allSeen = [];

    public ?IrChunkDesignerInput $seen = null;

    public int $calls = 0;

    /** @var (Closure(IrChunkDesignerInput): IrChunkDesignerResponse)|null */
    private ?Closure $responder = null;

    /** @var array<int, \Throwable>  if set, the call at index N throws (one-shot per index) */
    private array $throwsAtCall = [];

    /**
     * @param  Closure(IrChunkDesignerInput): IrChunkDesignerResponse  $responder
     */
    public function respondWith(Closure $responder): void
    {
        $this->responder = $responder;
    }

    public function throwOnCall(int $callIndex, \Throwable $e): void
    {
        $this->throwsAtCall[$callIndex] = $e;
    }

    public function run(IrChunkDesignerInput $input): IrChunkDesignerResponse
    {
        $this->allSeen[] = $input;
        $this->seen = $input;
        $thisCall = $this->calls;
        $this->calls++;

        if (isset($this->throwsAtCall[$thisCall])) {
            throw $this->throwsAtCall[$thisCall];
        }

        return ($this->responder ?? $this->defaultResponder())($input);
    }

    /**
     * Default: one Ir per chunk_page with the PageSlug-derived slug.
     * Slug fidelity is critical — a mismatch silently fails IrPass's
     * per-chunk diff.
     *
     * @return Closure(IrChunkDesignerInput): IrChunkDesignerResponse
     */
    private function defaultResponder(): Closure
    {
        return static function (IrChunkDesignerInput $input): IrChunkDesignerResponse {
            /** @var array<int, Ir> $pages */
            $pages = [];
            /** @var array<int, InventoryPage> $chunk */
            $chunk = $input->chunk_pages->items();
            foreach ($chunk as $i => $page) {
                $pages[] = new Ir(
                    page_slug: PageSlug::of($page),
                    page_title: $page->label,
                    nav_order: $i,
                    blocks: new DataCollection(IrBlock::class, [
                        new IrBlock(
                            component_type: 'hero',
                            content_brief: "Hero for {$page->label}",
                        ),
                        new IrBlock(
                            component_type: 'paragraph',
                            content_brief: "Intro paragraph for {$page->label}",
                        ),
                    ]),
                );
            }

            return new IrChunkDesignerResponse(
                pages: new DataCollection(Ir::class, $pages),
            );
        };
    }
}
