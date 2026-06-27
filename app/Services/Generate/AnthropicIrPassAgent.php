<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\GlobalStyleBrief;
use App\Data\InventoryPage;
use App\Data\Ir;
use App\Data\IrBlock;
use App\Data\IrPassAgentResponse;
use App\Data\IrPassInput;
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

// laravel/ai Agent that runs ONE Opus 4.8 call to produce the
// GlobalStyleBrief + per-page IR for every Keep content page. Anthropic +
// claude-opus-4-8 are pinned via attributes so config/ai.php's default
// (OpenAI) can't accidentally route this to the wrong provider — see
// CLAUDE.md "AI configuration gotcha".
//
// Used only in production wiring. Tests exercise FakeIrPassAgent through
// the same IrPassAgent interface.
//
// TODO: verify the AgentResponse → structured-data path against future
// laravel/ai releases. Today we decode $response->text as JSON.
#[ProviderAttribute(Lab::Anthropic)]
#[ModelAttribute('claude-opus-4-8')]
// Opus 4.8 + structured-output for many pages can run several minutes.
// The HTTP client's default 60s would trip on real multi-page calls
// before the model has streamed the full structured response back.
#[TimeoutAttribute(600)]
final class AnthropicIrPassAgent implements Agent, HasStructuredOutput, IrPassAgent
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
            You are designing the information architecture for a rebuilt
            youth-sports organization website on the TeamLinkt platform.
            The source SportsEngine site is being converted; the planner has
            already decided which pages survive as content (the keep_pages
            you receive) and which become TeamLinkt platform blocks (which
            you do NOT receive — they are handled elsewhere).

            For EACH keep page you receive its REAL captured body in the
            `body_markdown` field — that is the actual content the live
            SportsEngine site renders for that page. You design the IR
            FROM that body. You are NOT writing the page; you are deciding
            how the body's content should be structured as blocks.

            You produce ONE structured response in a single call:

              - brand_voice          : 2-3 sentences describing the site's
                                       tone (warm, professional, community-
                                       focused, etc.). Derive from the org
                                       name, page bodies, and any voice_hint
                                       — let the actual writing style on the
                                       pages anchor this.
              - palette              : hex color tokens — primary, secondary,
                                       accent, background, text. If the input
                                       palette has values, preserve or refine
                                       them; otherwise propose sensible ones
                                       consistent with the brand.
              - layout_conventions   : 4-8 short rules (e.g. "Use full-bleed
                                       heroes on landing pages", "Lead About
                                       Us with a group photo"). These
                                       reflect the site's character —
                                       observed from the bodies, consistent
                                       across pages.
              - pages                : one entry per page in keep_pages, in
                                       the same order. Each has page_slug,
                                       page_title, nav_order, and an ordered
                                       list of abstract block intents.

            FAITHFULNESS RULES (CRITICAL — design from body, do NOT invent):

            - DESIGN from the provided body, do NOT rewrite or invent copy.
              `content_brief` is a STRUCTURAL POINTER to material that
              exists in body_markdown ("render the four-pillar coaching
              philosophy from the body as a 4-column card grid"; "show
              the registration steps as an ordered list"), NEVER invented
              prose ("welcome to our premier youth basketball organization
              where dreams come true").

            - If the body is short or thin, the IR should be short and thin
              to match. Do NOT pad a 100-word About page with fabricated
              sections. Faithful-rebuild means the rebuilt page reflects
              what's actually on the source page.

            - Use the body's structure as the strongest hint to block
              boundaries: headings often signal a new block; lists become
              list or card blocks; image references become image / gallery
              blocks.

            - Block-fill (a later, separate pass) is what actually writes
              the rendered copy from the body. Your job is the
              architecture: which blocks, in what order, pointing at which
              piece of the body.

            BLOCK INTENT RULES (CRITICAL):

            - component_type is an ABSTRACT NAME describing the block's
              purpose. Recommended vocabulary: 'hero', 'heading',
              'paragraph', 'image', 'cta', 'columns', 'card', 'list',
              'quote', 'gallery', 'form', 'video', 'team_grid',
              'sponsor_strip', 'social_links', 'contact_card', 'faq_list'.
              Pick the most descriptive name for the intent. If none fit,
              invent a similarly-shaped lowercase_snake_case name.

            - DO NOT use Puck-specific or framework-specific PROP names
              like 'background_image', 'subheading', 'cta_label' as a
              component_type — those are properties of a hero, not block
              types. The assembler maps abstract block intents to real
              schema components in a later phase.

            - asset_refs is an array of S3 keys for images already
              extracted. Leave empty when unknown.

            - Order blocks naturally: hero / intro at top, deep content in
              the middle, CTA / related links at the bottom.

            - Pages with different nav roles should have different layouts:
              a landing hub (Home, About Us) leans hero + intro + featured
              cards; a deep policy page leans heading + paragraphs;
              a directory page leans grid/cards. The body anchors this —
              don't impose a hero on a page whose body has no hero
              material.

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

        $palette = $schema->object([
            'primary' => $schema->string()->required(),
            'secondary' => $schema->string(),
            'accent' => $schema->string(),
            'background' => $schema->string(),
            'text' => $schema->string(),
        ])->withoutAdditionalProperties();

        return [
            'brand_voice' => $schema->string()->required(),
            'palette' => $palette->required(),
            'layout_conventions' => $schema->array()->items($schema->string())->required(),
            'pages' => $schema->array()->items($page)->required(),
        ];
    }

    public function run(IrPassInput $input): IrPassAgentResponse
    {
        // Empty-input short-circuit lives in IrPass orchestration so the
        // agent contract is simple: every call is a real Opus call.
        $userPrompt = $this->buildUserPrompt($input);
        $response = $this->prompt($userPrompt);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($response->text, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("IR pass response was not valid JSON: {$e->getMessage()}");
        }

        return $this->responseFromDecoded($decoded, $input);
    }

    private function buildUserPrompt(IrPassInput $input): string
    {
        // Index bodies by slug so the per-page payload can include the
        // matching body verbatim. IrPass guarantees keep_pages and
        // keep_page_bodies are aligned by PageSlug::of(); the lookup is
        // defensive — a missing body would surface as empty body_markdown
        // (which the prompt's faithfulness rules tell the model to design
        // thinly from).
        /** @var array<string, KeepPageContent> $bodyBySlug */
        $bodyBySlug = [];
        /** @var array<int, KeepPageContent> $bodies */
        $bodies = $input->keep_page_bodies->items();
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
            'nav' => array_map(
                static fn (NavItem $item): array => [
                    'label' => $item->label,
                    'slug' => $item->page_slug,
                    'order' => $item->order,
                ],
                $input->nav->items(),
            ),
            'keep_pages' => array_map(
                function (InventoryPage $p) use ($bodyBySlug): array {
                    $slug = $this->slugOf($p);
                    $markdown = '';
                    /** @var array<int, string> $images */
                    $images = [];
                    if (isset($bodyBySlug[$slug])) {
                        $markdown = $bodyBySlug[$slug]->markdown;
                        $images = $bodyBySlug[$slug]->image_urls;
                    }

                    return [
                        'page_slug' => $slug,
                        'page_title' => $p->label,
                        'url' => $p->url,
                        'nav_path' => $p->nav_path,
                        'depth' => $p->depth,
                        // THE PROVIDED PAGE BODY — design from this, do
                        // NOT rewrite or invent copy. See the faithfulness
                        // rules in the system prompt.
                        'body_markdown' => $markdown,
                        'body_image_urls' => $images,
                    ];
                },
                $input->keep_pages->items(),
            ),
        ];

        return 'Design the IR + GlobalStyleBrief for this site. '
            .'The `body_markdown` per keep_page is the REAL captured body — '
            .'design FROM it, do not invent copy.'.PHP_EOL.
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function responseFromDecoded(array $decoded, IrPassInput $input): IrPassAgentResponse
    {
        $brandVoice = is_string($decoded['brand_voice'] ?? null) ? $decoded['brand_voice'] : '';

        /** @var array<string, string> $palette */
        $palette = [];
        $rawPalette = $decoded['palette'] ?? [];
        if (is_array($rawPalette)) {
            foreach ($rawPalette as $token => $hex) {
                if (is_string($token) && is_string($hex) && $hex !== '') {
                    $palette[$token] = $hex;
                }
            }
        }

        /** @var array<int, string> $layoutConventions */
        $layoutConventions = [];
        $rawLayout = $decoded['layout_conventions'] ?? [];
        if (is_array($rawLayout)) {
            foreach ($rawLayout as $rule) {
                if (is_string($rule) && $rule !== '') {
                    $layoutConventions[] = $rule;
                }
            }
        }

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

        return new IrPassAgentResponse(
            style_brief: new GlobalStyleBrief(
                brand_voice: $brandVoice,
                palette: $palette,
                layout_conventions: $layoutConventions,
                nav: $input->nav,
            ),
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

    private function slugOf(InventoryPage $page): string
    {
        // Delegated to PageSlug so IrPass and the agent always agree on the
        // identifier — a mismatch would silently drop pages on the diff.
        return PageSlug::of($page);
    }
}
