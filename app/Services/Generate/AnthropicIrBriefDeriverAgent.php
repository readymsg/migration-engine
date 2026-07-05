<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\GlobalStyleBrief;
use App\Data\IrBriefDeriverInput;
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

// laravel/ai Agent: ONE Opus 4.8 call producing the GlobalStyleBrief
// for a whole site. Receives a BOUNDED sample of representative pages
// (depth-0 + fallback, capped at IrPass::BRIEF_SAMPLE_LIMIT) plus
// the site's nav + brand. Returns brand_voice + palette +
// layout_conventions only — no per-page IR (that's the chunk-designer's
// job in subsequent calls).
//
// Anthropic + claude-opus-4-8 pinned via attributes so config/ai.php's
// default (OpenAI) can't accidentally route this elsewhere.
//
// Used only in production wiring. Tests exercise FakeIrBriefDeriverAgent
// through the same IrBriefDeriverAgent interface.
#[ProviderAttribute(Lab::Anthropic)]
#[ModelAttribute('claude-opus-4-8')]
#[TimeoutAttribute(600)]
final class AnthropicIrBriefDeriverAgent implements Agent, HasStructuredOutput, IrBriefDeriverAgent
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
            You are designing the GLOBAL STYLE BRIEF for a youth-sports
            organization website that is being rebuilt on the TeamLinkt
            platform from a source SportsEngine site.

            The brief you produce is the SINGULAR cross-page coherence
            anchor for the entire site. Subsequent IR-design calls (one
            per chunk of pages) will receive your brief as LOCKED input
            and conform every page's block intent to it. If your brief
            is inconsistent or thin, the rebuilt site will feel
            stitched-together — so anchor each output to evidence from
            the provided pages.

            You receive a BOUNDED SAMPLE of the site (sample_pages with
            their REAL captured body_markdown). On a large site this is
            a representative slice, not every page. `total_keep_pages`
            tells you the full site size for context. Infer the site's
            character from the sample bodies — they ARE the actual
            writing style on the live site.

            You produce ONE structured response:

              - brand_voice          : 2-3 sentences describing the site's
                                       tone (warm, professional, community-
                                       focused, etc.). Derive from the org
                                       name, page bodies, and any voice_hint.
                                       Let the actual writing style on the
                                       pages anchor this.
              - palette              : hex color tokens — primary, secondary,
                                       accent, background, text. If the
                                       input brand palette has values,
                                       preserve or refine them; otherwise
                                       propose sensible ones consistent
                                       with the brand and content. Always
                                       emit a primary token (required).
              - layout_conventions   : 4-8 short rules describing how
                                       blocks should be laid out across
                                       the site (e.g. "Use full-bleed
                                       heroes on landing pages", "Lead
                                       About Us with a group photo",
                                       "Lay out the Board page as a
                                       people-grid"). These reflect the
                                       site's character — observed from
                                       the bodies — and become the
                                       cross-chunk coherence contract.

            DO NOT design per-page IR. Your output has NO `pages` field —
            that's a separate call's job.

            Return strictly the structured JSON. No prose outside the schema.
            PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
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
        ];
    }

    public function run(IrBriefDeriverInput $input): GlobalStyleBrief
    {
        $userPrompt = $this->buildUserPrompt($input);
        $response = $this->prompt($userPrompt);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($response->text, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Brief-deriver response was not valid JSON: {$e->getMessage()}");
        }

        return $this->briefFromDecoded($decoded, $input);
    }

    private function buildUserPrompt(IrBriefDeriverInput $input): string
    {
        /** @var array<string, KeepPageContent> $bodyBySlug */
        $bodyBySlug = [];
        /** @var array<int, KeepPageContent> $bodies */
        $bodies = $input->sample_bodies->items();
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
            'total_keep_pages' => $input->total_keep_pages,
            'sample_pages' => array_map(
                function ($p) use ($bodyBySlug): array {
                    $slug = PageSlug::of($p);
                    // IrPass guarantees sample_pages and sample_bodies
                    // are slug-aligned; phpstan infers this lookup is
                    // total (collection-typed input). Empty body
                    // fallback would only fire on an orchestration
                    // contract break — surfacing as empty body in the
                    // prompt is preferable to throwing.
                    $body = $bodyBySlug[$slug] ?? null;

                    return [
                        'page_slug' => $slug,
                        'page_title' => $p->label,
                        'depth' => $p->depth,
                        'body_markdown' => $body !== null ? $body->markdown : '',
                    ];
                },
                $input->sample_pages->items(),
            ),
        ];

        return 'Derive the GlobalStyleBrief for this site from the bounded sample below. '
            .'The `body_markdown` per sample_page is the REAL captured body — '
            .'anchor brand_voice and layout_conventions in what you observe.'.PHP_EOL.
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function briefFromDecoded(array $decoded, IrBriefDeriverInput $input): GlobalStyleBrief
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

        return new GlobalStyleBrief(
            brand_voice: $brandVoice,
            palette: $palette,
            layout_conventions: $layoutConventions,
            nav: $input->nav,
        );
    }
}
