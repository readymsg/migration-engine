<?php

declare(strict_types=1);

namespace App\Services\Plan;

use App\Data\ClassificationResponse;
use App\Data\DecisionAction;
use App\Data\InventoryPage;
use App\Data\PlatformBlockType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use JsonException;
use Laravel\Ai\Attributes\Model as ModelAttribute;
use Laravel\Ai\Attributes\Provider as ProviderAttribute;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use RuntimeException;

// laravel/ai Agent pinning Anthropic Haiku 4.5 explicitly. config/ai.php
// defaults to OpenAI (see CLAUDE.md note); these attributes override that
// per-agent so misconfiguration of the package default can't accidentally
// route the keep/drop call through the wrong provider.
//
// Used only in production wiring — the test exercises FakeClassifierAgent
// through the same ClassifierAgent interface.
//
// TODO: verify the exact AgentResponse → structured-data path the laravel/ai
// 0.8 release uses (decoding $response->text as JSON works today; if a later
// release surfaces typed structured data on the response, switch to it).
#[ProviderAttribute(Lab::Anthropic)]
#[ModelAttribute('claude-haiku-4-5-20251001')]
final class AnthropicClassifierAgent implements Agent, ClassifierAgent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
            You are classifying pages from a youth-sports organization's existing
            SportsEngine site for a rebuild on the TeamLinkt platform.

            For each page in `pages`, output one decision. Possible actions:

              - keep              : preserve as its own page in the new site
              - merge             : merge into another page (set `merged_into` to that
                                    page's url)
              - drop              : the page has no value to carry over (e.g. stale event notice)
              - park              : you are uncertain — needs a human to review
              - platform_dynamic  : page IS a live-data listing TeamLinkt regenerates
                                    from its own data — also set `platform_block_type` to
                                    one of: schedule, scores, standings, roster, teams,
                                    divisions, contacts

            HARD RULES
            - Be biased toward recall. If you are not strongly confident a page
              should be dropped, prefer 'keep' or 'park' instead.
            - 'platform_dynamic' is ONLY for pages that ARE live-data listings — a
              standings table, a schedule grid, a scores page, a team/division
              directory, a contacts directory. NEVER use it for informational pages
              ABOUT those topics (a "tryouts info" page or an "about our schedule"
              page is 'keep', not 'platform_dynamic'). A false 'platform_dynamic'
              replaces real content with an empty block — when in doubt, choose
              'keep'.
            - `confidence` is 0..1 — your confidence in the action, not the page.
            - `reason` is one short sentence (< 120 chars).
            - Do NOT return 'dynamic' — dynamic SE features are handled elsewhere.
            - Return one decision per input page, in the same order as `pages`.
            PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        $decision = $schema->object([
            'action' => $schema->string()->enum(['keep', 'merge', 'drop', 'park', 'platform_dynamic'])->required(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
            'reason' => $schema->string()->required(),
            'merged_into' => $schema->string(),
            'platform_block_type' => $schema->string()->enum([
                'schedule', 'scores', 'standings', 'roster', 'teams', 'divisions', 'contacts',
            ]),
        ])->withoutAdditionalProperties();

        return [
            'decisions' => $schema->array()->items($decision)->required(),
        ];
    }

    public function classifyBatch(array $batch, string $brandVoiceHint = ''): array
    {
        if ($batch === []) {
            return [];
        }

        $userPrompt = $this->buildUserPrompt($batch, $brandVoiceHint);
        $response = $this->prompt($userPrompt);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($response->text, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Classifier response was not valid JSON: {$e->getMessage()}");
        }

        $decisions = $decoded['decisions'] ?? null;
        if (! is_array($decisions) || count($decisions) !== count($batch)) {
            throw new RuntimeException(
                'Classifier returned '.(is_array($decisions) ? count($decisions) : 'no').
                ' decisions for a batch of '.count($batch).' pages'
            );
        }

        $out = [];
        foreach ($decisions as $i => $row) {
            if (! is_array($row)) {
                throw new RuntimeException("Classifier decision #{$i} was not an object");
            }
            $action = is_string($row['action'] ?? null) ? $row['action'] : '';
            $confidence = is_numeric($row['confidence'] ?? null) ? (float) $row['confidence'] : 0.0;
            $reason = is_string($row['reason'] ?? null) ? $row['reason'] : '';
            $mergedInto = is_string($row['merged_into'] ?? null) && $row['merged_into'] !== ''
                ? $row['merged_into']
                : null;
            $platformBlockType = is_string($row['platform_block_type'] ?? null) && $row['platform_block_type'] !== ''
                ? PlatformBlockType::tryFrom($row['platform_block_type'])
                : null;

            $out[$i] = new ClassificationResponse(
                action: DecisionAction::from($action),
                confidence: $confidence,
                reason: $reason,
                merged_into: $mergedInto,
                platform_block_type: $platformBlockType,
            );
        }

        return $out;
    }

    /**
     * @param  array<int, InventoryPage>  $batch
     */
    private function buildUserPrompt(array $batch, string $brandVoiceHint): string
    {
        $pages = [];
        foreach ($batch as $i => $page) {
            $pages[] = [
                'index' => $i,
                'label' => $page->label,
                'url' => $page->url,
                'nav_path' => $page->nav_path,
                'depth' => $page->depth,
            ];
        }
        $payload = [
            'brand_voice' => $brandVoiceHint,
            'pages' => $pages,
        ];

        return 'Classify these pages:'.PHP_EOL.json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
