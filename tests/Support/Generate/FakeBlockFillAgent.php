<?php

declare(strict_types=1);

namespace Tests\Support\Generate;

use App\Data\BlockFillInput;
use App\Data\FilledBlock;
use App\Data\FilledPage;
use App\Data\IrBlock;
use App\Services\Generate\BlockFillAgent;
use Closure;
use Spatie\LaravelData\DataCollection;
use Throwable;

// Offline fake. Records every input the agent received (in call order)
// so tests can assert exactly what the orchestration sent each
// GeneratePageJob. A custom responder can branch on call index or
// page slug to simulate a particular page throwing — that exception
// flows into the GeneratePageJob's Throwable catch and lands in the
// result store as a BlockFillFailure, exercising the reconciliation
// path end-to-end without a real Sonnet call.
final class FakeBlockFillAgent implements BlockFillAgent
{
    /** @var array<int, BlockFillInput>  every input received, in call order */
    public array $allSeen = [];

    public int $calls = 0;

    /** @var (Closure(BlockFillInput): FilledPage)|null */
    private ?Closure $responder = null;

    /**
     * @param  Closure(BlockFillInput): FilledPage  $responder
     */
    public function respondWith(Closure $responder): void
    {
        $this->responder = $responder;
    }

    public function throwForSlug(string $slug, Throwable $error): void
    {
        $existing = $this->responder ?? $this->defaultResponder();
        $this->responder = static function (BlockFillInput $input) use ($slug, $error, $existing): FilledPage {
            if ($input->page_slug === $slug) {
                throw $error;
            }

            return $existing($input);
        };
    }

    public function run(BlockFillInput $input): FilledPage
    {
        $this->allSeen[] = $input;
        $this->calls++;

        return ($this->responder ?? $this->defaultResponder())($input);
    }

    /**
     * Default: turns each IrBlock into a single FilledBlock with mock
     * schema-shaped props. Echoes content_brief into source_brief and
     * picks the first ~120 chars of body_markdown as a source_quote
     * so source-anchor wiring is exercised end-to-end. Real assertions
     * on prop shape live in BlockFillTest, not here.
     *
     * @return Closure(BlockFillInput): FilledPage
     */
    private function defaultResponder(): Closure
    {
        return static function (BlockFillInput $input): FilledPage {
            $quote = substr($input->body_markdown, 0, 120);

            /** @var array<int, FilledBlock> $blocks */
            $blocks = [];
            /** @var array<int, IrBlock> $items */
            $items = $input->ir->blocks->items();
            foreach ($items as $b) {
                $blocks[] = new FilledBlock(
                    component_type: self::schemaTypeFor($b->component_type),
                    props: self::mockPropsFor($b->component_type, $input->ir->page_title),
                    source_brief: $b->content_brief,
                    source_quote: $quote,
                );
            }

            return new FilledPage(
                page_slug: $input->page_slug,
                page_title: $input->ir->page_title,
                nav_order: $input->ir->nav_order,
                blocks: new DataCollection(FilledBlock::class, $blocks),
                self_assessment: 'fake: drafted, audited, revised — props anchored to body_markdown',
                confidence: 0.82,
            );
        };
    }

    private static function schemaTypeFor(string $abstract): string
    {
        return match ($abstract) {
            'hero', 'banner' => 'Hero',
            'heading', 'section_heading' => 'Heading',
            'paragraph', 'body', 'text', 'intro', 'list' => 'Text',
            'image', 'photo' => 'Image',
            'card', 'feature' => 'Card',
            'cta', 'button', 'register', 'buttons' => 'ButtonGroup',
            default => 'Text',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function mockPropsFor(string $abstract, string $title): array
    {
        return match (self::schemaTypeFor($abstract)) {
            'Hero' => ['heading' => $title, 'subheading' => 'fake subheading'],
            'Heading' => ['text' => $title, 'level' => 'h2'],
            'Text' => ['body' => 'fake body from page '.$title],
            'Image' => ['src' => 'https://example.test/image.jpg', 'alt' => $title],
            'Card' => ['title' => $title, 'body' => 'fake card body'],
            'ButtonGroup' => ['buttons' => [['label' => 'Register', 'href' => '#']]],
            default => [],
        };
    }
}
