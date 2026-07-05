<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\InventoryPage;
use App\Data\Ir;
use App\Data\IrBlock;
use App\Data\IrChunkDesignerInput;
use App\Data\IrChunkDesignerResponse;
use App\Data\KeepPageContent;
use App\Data\NavItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use JsonException;
use Laravel\Ai\Attributes\Model as ModelAttribute;
use Laravel\Ai\Attributes\Provider as ProviderAttribute;
use Laravel\Ai\Attributes\Timeout as TimeoutAttribute;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use RuntimeException;
use Spatie\LaravelData\DataCollection;

// laravel/ai Agent: ONE Opus 4.8 call designing per-page IR for a
// chunk of keep-content pages (≤ IrPass::CHUNK_PAGE_LIMIT). Receives
// the GlobalStyleBrief as LOCKED input — does NOT propose its own
// voice/palette/conventions. The brief was designed by the brief-
// deriver in a prior call; every chunk's IR conforms to the same
// brief, which is how cross-chunk coherence is preserved.
//
// Anthropic + claude-opus-4-8 pinned via attributes.
//
// Used only in production wiring. Tests exercise FakeIrChunkDesignerAgent
// through the same IrChunkDesignerAgent interface.
#[ProviderAttribute(Lab::Anthropic)]
#[ModelAttribute('claude-opus-4-8')]
#[TimeoutAttribute(600)]
final class AnthropicIrChunkDesignerAgent implements Agent, HasStructuredOutput, IrChunkDesignerAgent
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
            You are designing the per-page block intent (IR) for a CHUNK
            of pages from a youth-sports organization website being
            rebuilt on the TeamLinkt platform from a source SportsEngine
            site.

            You are ONE of multiple chunked calls for this site. The
            GlobalStyleBrief (`style_brief` in your input) was designed
            in a separate call against a bounded sample of the whole
            site, and is LOCKED INPUT for you — it is the cross-chunk
            coherence contract. Every page you design must conform to
            its layout_conventions and reflect its brand_voice. DO NOT
            propose your own brief; DO NOT mutate the voice or palette.

            You receive `chunk_pages` — the slice of keep-content pages
            you design (and ONLY those). The full site nav and brand
            are passed for context — you know what other pages exist
            even though you don't design them. Use the nav to inform
            block ordering and CTAs (e.g. "this is depth-0, lean hero;
            the Programs page is at order 3, link from CTAs").

            For EACH chunk page you receive its REAL captured body in
            `body_markdown`. Design the IR FROM that body. You are NOT
            writing copy; you are deciding how the body's content
            should be structured as blocks.

            Your output is just the `pages` field — one entry per
            chunk_page, in the same order. No `brand_voice`, no
            `palette`, no `layout_conventions` (those are locked).

            SLUG-ECHO RULE (CRITICAL — pages will be silently lost otherwise):

            - `page_slug` is an IDENTIFIER WE PROVIDED YOU in each
              input chunk_page. ECHO IT BACK CHARACTER-FOR-CHARACTER —
              same format, same value. Do NOT invent a "nicer" human-
              readable slug, do NOT slugify the page title, do NOT
              change `_` to `-` or vice versa, do NOT strip the
              `page-` prefix.
            - Slugs that don't match what we sent are treated as
              silent drops by the orchestration's per-chunk diff. The
              page will land in failures, not in the rebuild.

            FAITHFULNESS RULES (CRITICAL — design from body, do NOT invent):

            - DESIGN from the provided body, do NOT rewrite or invent
              copy. `content_brief` is a STRUCTURAL POINTER to material
              that exists in body_markdown ("render the four-pillar
              coaching philosophy as a 4-column card grid"; "show the
              registration steps as an ordered list"), NEVER invented
              prose.

            - If the body is short or thin, the IR should be short and
              thin to match. Do NOT pad a 100-word About page with
              fabricated sections.

            - Use the body's structure as the strongest hint to block
              boundaries: headings often signal a new block; lists
              become list or card blocks; image references become
              image / gallery blocks.

            - Block-fill (a later, separate pass) is what actually
              writes the rendered copy from the body. Your job is the
              architecture: which blocks, in what order, pointing at
              which piece of the body.

            BLOCK INTENT RULES (CRITICAL):

            - component_type is an ABSTRACT NAME describing the block's
              purpose. Recommended vocabulary: 'hero', 'heading',
              'paragraph', 'image', 'cta', 'columns', 'card', 'list',
              'quote', 'gallery', 'form', 'video', 'team_grid',
              'sponsor_strip', 'social_links', 'contact_card',
              'faq_list'. Pick the most descriptive name for the
              intent. If none fit, invent a similarly-shaped
              lowercase_snake_case name.

            - DO NOT use Puck-specific or framework-specific PROP names
              like 'background_image', 'subheading', 'cta_label' as a
              component_type — those are properties of a hero, not
              block types. The assembler maps abstract block intents
              to real schema components in a later phase.

            - asset_refs is an array of S3 keys for images already
              extracted. Leave empty when unknown.

            - Order blocks naturally: hero / intro at top, deep content
              in the middle, CTA / related links at the bottom.

            - Apply the style_brief's layout_conventions — that's the
              site-wide coherence anchor. A convention like "Lead About
              Us with a group photo" means: if this chunk's pages
              include an About-shaped page, lead with an image block.

            Return strictly the structured JSON. No prose outside the schema.
            PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        $block = $schema->object([
            'component_type' => $schema->string()->required(),
            'content_brief' => $schema->string()->required(),
            'asset_refs' => $schema->array()->items($schema->string()),
        ])->withoutAdditionalProperties();

        $page = $schema->object([
            'page_slug' => $schema->string()->required(),
            'page_title' => $schema->string()->required(),
            'nav_order' => $schema->integer()->required(),
            'blocks' => $schema->array()->items($block)->required(),
        ])->withoutAdditionalProperties();

        return [
            'pages' => $schema->array()->items($page)->required(),
        ];
    }

    public function run(IrChunkDesignerInput $input): IrChunkDesignerResponse
    {
        $userPrompt = $this->buildUserPrompt($input);
        $response = $this->prompt($userPrompt);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($response->text, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Chunk-designer response was not valid JSON for chunk '.
                ($input->chunk_index + 1)."/{$input->total_chunks}: {$e->getMessage()}"
            );
        }

        return $this->responseFromDecoded($decoded);
    }

    private function buildUserPrompt(IrChunkDesignerInput $input): string
    {
        /** @var array<string, KeepPageContent> $bodyBySlug */
        $bodyBySlug = [];
        /** @var array<int, KeepPageContent> $bodies */
        $bodies = $input->chunk_bodies->items();
        foreach ($bodies as $body) {
            $bodyBySlug[$body->page_slug] = $body;
        }

        $payload = [
            'org_id' => $input->org_id,
            'source_url' => $input->source_url,
            'brand' => [
                'voice_hint' => $input->brand->voice_hint,
                'palette' => $input->brand->palette,
            ],
            'style_brief' => [
                'brand_voice' => $input->style_brief->brand_voice,
                'palette' => $input->style_brief->palette,
                'layout_conventions' => $input->style_brief->layout_conventions,
            ],
            'nav' => array_map(
                static fn (NavItem $item): array => [
                    'label' => $item->label,
                    'slug' => $item->page_slug,
                    'order' => $item->order,
                ],
                $input->nav->items(),
            ),
            'chunk_index' => $input->chunk_index + 1,
            'total_chunks' => $input->total_chunks,
            'chunk_pages' => array_map(
                function (InventoryPage $p) use ($bodyBySlug): array {
                    $slug = PageSlug::of($p);
                    // IrPass guarantees chunk_pages and chunk_bodies
                    // are slug-aligned parallel collections. Defensive
                    // null fallback below is for orchestration
                    // contract breaks only.
                    $body = $bodyBySlug[$slug] ?? null;

                    return [
                        'page_slug' => $slug,
                        'page_title' => $p->label,
                        'url' => $p->url,
                        'nav_path' => $p->nav_path,
                        'depth' => $p->depth,
                        'body_markdown' => $body !== null ? $body->markdown : '',
                        'body_image_urls' => $body !== null ? $body->image_urls : [],
                    ];
                },
                $input->chunk_pages->items(),
            ),
        ];

        return 'Design the IR for chunk '.($input->chunk_index + 1)." of {$input->total_chunks}. "
            .'Conform to the LOCKED style_brief — do NOT propose your own. '
            .'Design FROM each body_markdown, do not invent copy.'.PHP_EOL.
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function responseFromDecoded(array $decoded): IrChunkDesignerResponse
    {
        /** @var array<int, Ir> $pages */
        $pages = [];
        $rawPages = $decoded['pages'] ?? [];
        if (is_array($rawPages)) {
            foreach ($rawPages as $rawPage) {
                if (! is_array($rawPage)) {
                    continue;
                }
                $pages[] = $this->irFromArray($rawPage);
            }
        }

        return new IrChunkDesignerResponse(
            pages: new DataCollection(Ir::class, $pages),
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function irFromArray(array $raw): Ir
    {
        /** @var array<int, IrBlock> $blocks */
        $blocks = [];
        $rawBlocks = $raw['blocks'] ?? [];
        if (is_array($rawBlocks)) {
            foreach ($rawBlocks as $rawBlock) {
                if (! is_array($rawBlock)) {
                    continue;
                }
                $assetRefs = [];
                $rawAssets = $rawBlock['asset_refs'] ?? [];
                if (is_array($rawAssets)) {
                    foreach ($rawAssets as $ref) {
                        if (is_string($ref) && $ref !== '') {
                            $assetRefs[] = $ref;
                        }
                    }
                }
                $blocks[] = new IrBlock(
                    component_type: is_string($rawBlock['component_type'] ?? null) ? $rawBlock['component_type'] : '',
                    content_brief: is_string($rawBlock['content_brief'] ?? null) ? $rawBlock['content_brief'] : '',
                    asset_refs: $assetRefs,
                );
            }
        }

        return new Ir(
            page_slug: is_string($raw['page_slug'] ?? null) ? $raw['page_slug'] : '',
            page_title: is_string($raw['page_title'] ?? null) ? $raw['page_title'] : '',
            nav_order: is_int($raw['nav_order'] ?? null) ? $raw['nav_order'] : (int) ($raw['nav_order'] ?? 0),
            blocks: new DataCollection(IrBlock::class, $blocks),
        );
    }
}
