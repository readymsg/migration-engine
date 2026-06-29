<?php

declare(strict_types=1);

namespace Tests\Unit\Generate;

use App\Data\BlockFillInput;
use App\Data\FilledPage;
use App\Data\GlobalStyleBrief;
use App\Data\Ir;
use App\Data\IrBlock;
use App\Data\NavItem;
use App\Services\Generate\AnthropicBlockFillAgent;
use App\Services\Schema\DefaultPuckComponentSchema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

// Tests the parser-throw hardening in AnthropicBlockFillAgent::
// filledPageFromDecoded. The parser used to silently `continue` past
// non-array `blocks` entries — an unreachable code path today (the
// laravel/ai Anthropic gateway uses tool-call structured output with
// strict schema validation) BUT a latent silent-loss vector if the
// gateway ever changes. Hardening converts "safe by transport accident"
// into "safe by construction."
//
// AnthropicBlockFillAgent is final + filledPageFromDecoded is private,
// so we invoke via reflection rather than subclassing. The production
// code path through run() would require a real laravel/ai gateway
// call; testing the decoder directly is the focused test of the
// throw branch.
final class AnthropicBlockFillAgentParserTest extends TestCase
{
    #[Test]
    public function non_array_blocks_field_throws_instead_of_silently_dropping(): void
    {
        $decoded = [
            'page_slug' => 'p',
            'page_title' => 'P',
            'nav_order' => 0,
            'blocks' => 'oops, this should have been an array',
            'self_assessment' => 'x',
            'confidence' => 0.5,
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("non-array 'blocks' field");

        $this->invokeFilledPageFromDecoded($decoded, $this->inputFor('p'));
    }

    #[Test]
    public function non_array_block_item_throws_instead_of_silently_dropping(): void
    {
        $decoded = [
            'page_slug' => 'p',
            'page_title' => 'P',
            'nav_order' => 0,
            'blocks' => [
                ['component_type' => 'Hero', 'props' => ['heading' => 'OK'], 'source_brief' => '', 'source_quote' => ''],
                'this string should have been an object',
            ],
            'self_assessment' => 'x',
            'confidence' => 0.5,
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-array block at index 1');

        $this->invokeFilledPageFromDecoded($decoded, $this->inputFor('p'));
    }

    #[Test]
    public function empty_blocks_array_still_parses_cleanly_into_an_empty_filled_page(): void
    {
        // The legitimate empty-FilledPage case (Sonnet emits blocks=[]
        // because it decided to skip the page) MUST still parse. The
        // hardening targets MALFORMED shapes only — empty arrays are
        // legitimate. The Assembler converts the empty FilledPage into
        // an AssemblyFailure downstream; that's the page-level
        // guarantee, separate from this parser hardening.
        $decoded = [
            'page_slug' => 'page-8932018',
            'page_title' => 'Adult Langdon Softball',
            'nav_order' => 4,
            'blocks' => [],
            'self_assessment' => 'No blocks emitted.',
            'confidence' => 0.1,
        ];

        /** @var FilledPage $filled */
        $filled = $this->invokeFilledPageFromDecoded($decoded, $this->inputFor('page-8932018'));

        $this->assertSame('page-8932018', $filled->page_slug);
        $this->assertSame(0, $filled->blocks->count());
        $this->assertSame(0.1, $filled->confidence);
    }

    #[Test]
    public function well_formed_blocks_parse_normally(): void
    {
        $decoded = [
            'page_slug' => 'p',
            'page_title' => 'P',
            'nav_order' => 0,
            'blocks' => [
                ['component_type' => 'Hero', 'props' => ['heading' => 'Welcome'], 'source_brief' => 'top of page', 'source_quote' => 'Welcome'],
                ['component_type' => 'Text', 'props' => ['body' => 'Body text'], 'source_brief' => 'intro', 'source_quote' => 'Body text'],
            ],
            'self_assessment' => 'fine',
            'confidence' => 0.9,
        ];

        /** @var FilledPage $filled */
        $filled = $this->invokeFilledPageFromDecoded($decoded, $this->inputFor('p'));

        $this->assertSame(2, $filled->blocks->count());
        $this->assertSame(0.9, $filled->confidence);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function invokeFilledPageFromDecoded(array $decoded, BlockFillInput $input): FilledPage
    {
        $agent = new AnthropicBlockFillAgent(new DefaultPuckComponentSchema);
        $rc = new ReflectionClass($agent);
        $method = $rc->getMethod('filledPageFromDecoded');

        /** @var FilledPage $result */
        $result = $method->invoke($agent, $decoded, $input);

        return $result;
    }

    private function inputFor(string $slug): BlockFillInput
    {
        return new BlockFillInput(
            org_id: 'ngin-test',
            page_slug: $slug,
            ir: new Ir(
                page_slug: $slug,
                page_title: 'P',
                nav_order: 0,
                blocks: new DataCollection(IrBlock::class, [
                    new IrBlock(component_type: 'hero', content_brief: 'something', asset_refs: []),
                ]),
            ),
            style_brief: new GlobalStyleBrief(
                brand_voice: '',
                palette: [],
                layout_conventions: [],
                nav: new DataCollection(NavItem::class, []),
            ),
            body_markdown: '# Header',
            body_image_urls: [],
        );
    }
}
