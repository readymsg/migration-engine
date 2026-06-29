<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\BlockFillInput;
use App\Data\FilledBlock;
use App\Data\FilledPage;
use App\Data\IrBlock;
use App\Data\NavItem;
use App\Services\Schema\ComponentSchema;
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

// laravel/ai Agent that runs ONE Sonnet 4.6 call per page to produce the
// FilledPage from the page's Ir + the REAL captured body + the per-conversion
// GlobalStyleBrief. Anthropic + claude-sonnet-4-6 pinned via attributes
// (config/ai.php defaults to OpenAI — see CLAUDE.md AI configuration gotcha).
//
// UNCACHED in this slice. Prompt-caching the shared prefix (schema + style
// brief + rubric) is the biggest speed/cost win at volume, but requires
// structured `system` blocks with cache_control which laravel/ai's gateway
// doesn't expose today (sends system as a plain string). Deferred to a
// dedicated slice — see CLAUDE.md known gaps.
//
// Used only in production wiring. Tests exercise FakeBlockFillAgent through
// the same BlockFillAgent interface.
#[ProviderAttribute(Lab::Anthropic)]
#[ModelAttribute('claude-sonnet-4-6')]
// Block-fill is per-page so each call is smaller than the IR pass, but a
// structured-output page with the full body markdown + revision pass can
// still take a couple of minutes. Match IrPass's generous 600s ceiling —
// the HTTP client's 60s default would trip on legitimate calls.
#[TimeoutAttribute(600)]
final class AnthropicBlockFillAgent implements Agent, BlockFillAgent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        private readonly ComponentSchema $schemaProvider,
    ) {}

    public function instructions(): string
    {
        // The schema text is dynamic only in the sense that it's serialized
        // from the ComponentSchema provider — for v1 that's the hand-written
        // default-Puck shape and never changes per call. Building the
        // instructions string lazily inside instructions() (rather than in
        // __construct) keeps the agent stateless across container reuse.
        $schemaSummary = $this->renderSchemaSummary();

        return <<<PROMPT
            You are filling block content for ONE page of a rebuilt
            youth-sports organization website on the TeamLinkt platform.
            The source SportsEngine site is being converted; upstream
            stages have already decided this page survives as content and
            have designed its Ir (an ordered list of ABSTRACT block
            intents pointing at material in the page's body).

            Your job: render each Ir block as a concrete schema-named
            Puck block with real prop values drawn FROM the provided
            `body_markdown`. The deterministic assembler (next stage)
            turns your FilledPage into Puck JSON — it does NOT do any
            content generation. If you don't write the copy from the
            body, no one else does.

            SCHEMA — the ONLY component_type values you may emit, with
            their required (*) and optional props:

            {$schemaSummary}

            ABSTRACT INTENT → SCHEMA MAPPING (the Ir uses abstract names;
            resolve them):

              - 'hero' / 'banner'                              → Hero
              - 'heading' / 'h1'..'h6' / 'section_heading'     → Heading
              - 'paragraph' / 'body' / 'text' / 'intro'         → Text
              - 'image' / 'photo'                              → Image
              - 'card' / 'feature'                             → Card
              - 'cta' / 'button' / 'register' / 'buttons'      → ButtonGroup
              - 'columns' / 'grid' / 'team_grid' / 'gallery' /
                'sponsor_strip' / 'faq_list'                    → Columns
              - 'list'                                         → Text (use a
                                                                  bulleted
                                                                  markdown body)
              - any unknown intent                             → pick the
                                                                  closest
                                                                  schema type
                                                                  and explain
                                                                  in
                                                                  self_assessment

            Multiple repeating items (a team_grid, an FAQ list, a sponsor
            strip) → wrap them in a Columns block whose `columns[].children`
            contain the per-item Card / Text / Image blocks.

            FAITHFULNESS — every prop value must be supported by
            body_markdown. This is the most important rule:

              - Do NOT invent names, dates, prices, contacts, programs,
                statistics, testimonials, claims, or facts that don't
                appear in the body.
              - If an Ir block's content_brief points at material the body
                doesn't actually contain, fill the prop with what IS in the
                body and LOWER your confidence accordingly. A thinner
                faithful page beats a padded fabricated one.
              - For CTA / button hrefs and image srcs: only use URLs,
                paths, or asset references present in the body. Do not
                guess hrefs. If the IR asks for a CTA but the body has no
                target link, set href to "#" (placeholder for the
                assembler) and note it in self_assessment.
              - If the body is thin, your blocks should be thin. Do NOT
                pad.

            SOURCE QUOTE — for each block, populate `source_quote` with
            the body snippet (verbatim, ≤ 240 chars) that anchored the
            block's content.

              - REQUIRED (non-empty) for content blocks: Hero, Heading,
                Text, Card. An empty source_quote on a content block means
                you fabricated the content — don't.
              - OPTIONAL (may be empty "") for prop-style blocks: Image
                (when alt/caption is derived but no body quote exists),
                ButtonGroup (when only labels/hrefs exist and there's no
                surrounding prose), Columns (which wrap children — the
                children carry their own quotes).
              - The quote MUST appear in body_markdown (substring match).
                Do not paraphrase the quote.

            GLOBAL STYLE BRIEF — the brand voice, palette, and layout
            conventions are provided per call. Match the voice — copy
            should read like the org wrote it. Apply layout conventions
            (e.g. "Use full-bleed heroes on landing pages") when the IR
            allows.

            SELF-CRITIQUE (in-pass — single call, no extra round trip):

              1. Draft the FilledPage following all rules above.
              2. Audit your draft against:
                   - Faithfulness: every prop value traceable to a body
                     substring (sample-check at least one prop per block).
                   - Schema-conformance: prop shapes match the schema for
                     each component_type; all required props present.
                   - Tone: matches brand_voice.
                   - Source quotes: present for content blocks, empty-OK
                     for prop-style.
              3. REVISE in place — fix any issues found in the audit.
              4. Set `self_assessment` to a 1-3 sentence reflection
                 describing what you audited and any remaining concerns.
              5. Set `confidence` (0..1) — honest assessment of how
                 faithfully the rebuilt page reflects the body. Low
                 confidence is fine and useful; do not inflate.

            Note on self_assessment and confidence: these are recorded as
            SOFT signals only. They are NOT used to gate page acceptance.
            The trusted score is computed structurally downstream (Puck
            validation, body-keyword landing, schema-conformance). Be
            honest, not optimistic.

            Return strictly the structured JSON. No prose outside the
            schema.
            PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        $block = $schema->object([
            'component_type' => $schema->string()->required(),
            // Props are open — shape varies per component_type. The
            // deterministic assembler validates against ComponentSchema
            // in the next slice; the structured-output layer just needs
            // to know it's an object.
            'props' => $schema->object()->required(),
            'source_brief' => $schema->string()->required(),
            'source_quote' => $schema->string(),
        ])->withoutAdditionalProperties();

        return [
            'page_slug' => $schema->string()->required(),
            'page_title' => $schema->string()->required(),
            'nav_order' => $schema->integer()->required(),
            'blocks' => $schema->array()->items($block)->required(),
            'self_assessment' => $schema->string()->required(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
        ];
    }

    public function run(BlockFillInput $input): FilledPage
    {
        $userPrompt = $this->buildUserPrompt($input);
        $response = $this->prompt($userPrompt);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($response->text, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                "Block-fill response was not valid JSON for {$input->page_slug}: {$e->getMessage()}"
            );
        }

        return $this->filledPageFromDecoded($decoded, $input);
    }

    private function buildUserPrompt(BlockFillInput $input): string
    {
        /** @var array<int, IrBlock> $irBlocks */
        $irBlocks = $input->ir->blocks->items();
        /** @var array<int, NavItem> $navItems */
        $navItems = $input->style_brief->nav->items();

        $payload = [
            'org_id' => $input->org_id,
            'page_slug' => $input->page_slug,
            'page_title' => $input->ir->page_title,
            'nav_order' => $input->ir->nav_order,
            'style_brief' => [
                'brand_voice' => $input->style_brief->brand_voice,
                'palette' => $input->style_brief->palette,
                'layout_conventions' => $input->style_brief->layout_conventions,
                'nav' => array_map(
                    static fn (NavItem $item): array => [
                        'label' => $item->label,
                        'slug' => $item->page_slug,
                        'order' => $item->order,
                    ],
                    $navItems,
                ),
            ],
            'ir_blocks' => array_map(
                static fn (IrBlock $b): array => [
                    'component_type' => $b->component_type,
                    'content_brief' => $b->content_brief,
                    'asset_refs' => $b->asset_refs,
                ],
                $irBlocks,
            ),
            // The REAL captured body — fill FROM this. No truncation.
            'body_markdown' => $input->body_markdown,
            'body_image_urls' => $input->body_image_urls,
        ];

        return 'Fill the page below. Render each ir_block as a schema-named '
            .'FilledBlock; every prop value must be supported by body_markdown. '
            .'Cite source_quote for each content block.'.PHP_EOL.
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function filledPageFromDecoded(array $decoded, BlockFillInput $input): FilledPage
    {
        /** @var array<int, FilledBlock> $blocks */
        $blocks = [];
        $rawBlocks = $decoded['blocks'] ?? [];
        // Schema declares `blocks` as a required array. If Sonnet emits a
        // non-array value, the gateway's tool-call schema validation
        // SHOULD reject it before we get here — refusing to silently
        // accept it makes the faithful-rebuild guarantee hold by
        // construction rather than by transport accident. If laravel/ai
        // ever changes its structured-output path away from tool calls,
        // this throw becomes the load-bearing check.
        if (! is_array($rawBlocks)) {
            $type = get_debug_type($rawBlocks);
            throw new RuntimeException(
                "Block-fill response had non-array 'blocks' field for {$input->page_slug} ".
                "(got {$type}); structured-output validation should have caught this — gateway change?"
            );
        }
        foreach ($rawBlocks as $i => $rawBlock) {
            if (! is_array($rawBlock)) {
                $type = get_debug_type($rawBlock);
                throw new RuntimeException(
                    "Block-fill response had non-array block at index {$i} for {$input->page_slug} ".
                    "(got {$type}); structured-output validation should have caught this — gateway change?"
                );
            }
            $rawProps = $rawBlock['props'] ?? [];
            /** @var array<string, mixed> $props */
            $props = is_array($rawProps) ? $rawProps : [];

            $sourceQuote = $rawBlock['source_quote'] ?? null;
            $sourceQuote = is_string($sourceQuote) && $sourceQuote !== ''
                ? $sourceQuote
                : null;

            $blocks[] = new FilledBlock(
                component_type: is_string($rawBlock['component_type'] ?? null)
                    ? $rawBlock['component_type']
                    : '',
                props: $props,
                source_brief: is_string($rawBlock['source_brief'] ?? null)
                    ? $rawBlock['source_brief']
                    : '',
                source_quote: $sourceQuote,
            );
        }

        $confidence = $decoded['confidence'] ?? 0.0;
        if (! is_numeric($confidence)) {
            $confidence = 0.0;
        }

        return new FilledPage(
            page_slug: is_string($decoded['page_slug'] ?? null) && $decoded['page_slug'] !== ''
                ? $decoded['page_slug']
                : $input->page_slug,
            page_title: is_string($decoded['page_title'] ?? null)
                ? $decoded['page_title']
                : $input->ir->page_title,
            nav_order: is_int($decoded['nav_order'] ?? null)
                ? $decoded['nav_order']
                : (int) ($decoded['nav_order'] ?? $input->ir->nav_order),
            blocks: new DataCollection(FilledBlock::class, $blocks),
            self_assessment: is_string($decoded['self_assessment'] ?? null)
                ? $decoded['self_assessment']
                : '',
            confidence: (float) $confidence,
        );
    }

    private function renderSchemaSummary(): string
    {
        $lines = [];
        foreach ($this->schemaProvider->all() as $def) {
            $fieldLines = [];
            foreach ($def->fields as $name => $field) {
                $req = $field->required ? '*' : '';
                $type = $field->type;
                $options = $field->options !== null && $field->options !== []
                    ? ' ('.implode('|', $field->options).')'
                    : '';
                $fieldLines[] = $name.$req.': '.$type.$options;
            }
            $lines[] = '  - '.$def->type.' { '.implode(', ', $fieldLines).' }';
        }

        return implode("\n", $lines);
    }
}
