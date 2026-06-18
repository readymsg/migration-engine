<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\ConversionLog;
use App\Data\ConversionStatus;
use App\Data\DecisionAction;
use App\Data\DecisionLedger;
use App\Data\GlobalStyleBrief;
use App\Data\Ir;
use App\Data\Manifest;
use App\Data\PuckOutput;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DtoSerializationTest extends TestCase
{
    #[Test]
    public function manifest_round_trips(): void
    {
        $payload = [
            'source_url' => 'https://example.sportngin.com',
            'org_id' => 'org_123',
            'structure' => [
                'nav' => [
                    [
                        'label' => 'Home',
                        'url' => 'https://example.sportngin.com/',
                        'kind' => 'page',
                        'children' => [],
                    ],
                    [
                        'label' => 'Teams',
                        'url' => 'https://example.sportngin.com/teams',
                        'kind' => 'dynamic',
                        'children' => [],
                    ],
                ],
                'pages_total' => 12,
            ],
            'provisioning' => [
                'teams' => [['name' => 'U10 Tigers', 'division' => 'U10', 'season' => 'Spring 2026']],
                'divisions' => [['name' => 'U10']],
                'admins' => [['name' => 'Jane', 'email' => 'jane@example.com', 'role' => 'commissioner']],
            ],
            'brand' => [
                'logo_source' => 'header',
                'logo_asset_ref' => 's3://assets/org_123/logo.png',
                'palette' => ['primary' => '#003366', 'secondary' => '#FFCC00'],
                'voice_hint' => 'warm, community-focused',
            ],
            'content_refs' => [
                ['url' => 'https://example.sportngin.com/about', 'scrape_ref' => 's3://scrapes/about.json', 'title' => 'About', 'nav_path' => ['About']],
            ],
            'asset_refs' => [
                ['s3_key' => 's3://assets/org_123/logo.png', 'mime_type' => 'image/png', 'bytes' => 12345],
            ],
            'confidence' => 0.87,
            'flags' => ['noisy_nav'],
        ];

        $manifest = Manifest::from($payload);
        $round = $manifest->toArray();

        $this->assertSame('org_123', $round['org_id']);
        $this->assertSame(12, $round['structure']['pages_total']);
        $this->assertCount(2, $round['structure']['nav']);
        $this->assertSame('U10 Tigers', $round['provisioning']['teams'][0]['name']);
        $this->assertSame('header', $round['brand']['logo_source']);
        $this->assertSame(0.87, $round['confidence']);
        $this->assertJson($manifest->toJson());
    }

    #[Test]
    public function ir_round_trips(): void
    {
        $ir = Ir::from([
            'page_slug' => 'home',
            'page_title' => 'Welcome',
            'nav_order' => 0,
            'blocks' => [
                ['component_type' => 'Hero', 'content_brief' => 'Welcoming hero with org name', 'asset_refs' => ['s3://assets/logo.png']],
                ['component_type' => 'Text', 'content_brief' => 'Short paragraph about the league', 'asset_refs' => []],
            ],
        ]);

        $round = $ir->toArray();

        $this->assertSame('home', $round['page_slug']);
        $this->assertSame(0, $round['nav_order']);
        $this->assertCount(2, $round['blocks']);
        $this->assertSame('Hero', $round['blocks'][0]['component_type']);
        $this->assertJson($ir->toJson());
    }

    #[Test]
    public function puck_output_round_trips(): void
    {
        $puck = PuckOutput::from([
            'page_slug' => 'home',
            'content' => [
                ['type' => 'Hero', 'props' => ['heading' => 'Welcome', 'subheading' => 'Spring 2026']],
                ['type' => 'Text', 'props' => ['body' => 'Lorem ipsum', 'align' => 'left']],
            ],
            'root' => ['title' => 'Home'],
            'zones' => [],
        ]);

        $round = $puck->toArray();

        $this->assertSame('home', $round['page_slug']);
        $this->assertCount(2, $round['content']);
        $this->assertSame('Welcome', $round['content'][0]['props']['heading']);
        $this->assertJson($puck->toJson());
    }

    #[Test]
    public function decision_ledger_round_trips(): void
    {
        $ledger = DecisionLedger::from([
            'entries' => [
                ['target' => 'https://x/coach-bios', 'action' => 'keep', 'reason' => 'high content density', 'confidence' => 0.91],
                ['target' => 'https://x/old-schedule', 'action' => 'park', 'reason' => 'low confidence, biased to keep-equivalent', 'confidence' => 0.42],
                ['target' => 'https://x/registration', 'action' => 'dynamic', 'reason' => 'matches dynamic signal', 'confidence' => 0.99],
            ],
        ]);

        $round = $ledger->toArray();

        $this->assertCount(3, $round['entries']);
        $this->assertSame(DecisionAction::Keep->value, $round['entries'][0]['action']);
        $this->assertSame(DecisionAction::Park->value, $round['entries'][1]['action']);
        $this->assertJson($ledger->toJson());
    }

    #[Test]
    public function conversion_log_round_trips(): void
    {
        $log = ConversionLog::from([
            'conversion_id' => 'conv_abc',
            'org_id' => 'org_123',
            'source_url' => 'https://example.sportngin.com',
            'status' => 'completed',
            'stage_confidences' => ['ingest' => 0.92, 'plan' => 0.81, 'generate' => 0.77, 'score' => 0.85],
            'decision_ledger' => [
                'entries' => [
                    ['target' => '/about', 'action' => 'keep', 'reason' => 'kept', 'confidence' => 0.9],
                ],
            ],
            'page_scores' => ['home' => 0.88, 'about' => 0.74],
            'duration_ms' => 312_000,
            'ai_cost_usd' => 1.24,
            'token_usage' => ['prompt' => 120000, 'completion' => 34000, 'cached' => 95000],
            'draft_link' => 'https://teamlinkt.test/drafts/01HX',
        ]);

        $round = $log->toArray();

        $this->assertSame('conv_abc', $round['conversion_id']);
        $this->assertSame(ConversionStatus::Completed->value, $round['status']);
        $this->assertSame(1.24, $round['ai_cost_usd']);
        $this->assertCount(1, $round['decision_ledger']['entries']);
        $this->assertSame(95000, $round['token_usage']['cached']);
        $this->assertJson($log->toJson());
    }

    #[Test]
    public function global_style_brief_round_trips(): void
    {
        $brief = GlobalStyleBrief::from([
            'brand_voice' => 'warm, community-focused',
            'palette' => ['primary' => '#003366', 'secondary' => '#FFCC00'],
            'layout_conventions' => ['Use full-bleed heroes on landing pages', 'Lead with team logos in cards'],
            'nav' => [
                ['label' => 'Home', 'page_slug' => 'home', 'order' => 0],
                ['label' => 'About', 'page_slug' => 'about', 'order' => 1],
            ],
        ]);

        $round = $brief->toArray();

        $this->assertSame('warm, community-focused', $round['brand_voice']);
        $this->assertSame('#003366', $round['palette']['primary']);
        $this->assertCount(2, $round['nav']);
        $this->assertSame(1, $round['nav'][1]['order']);
        $this->assertJson($brief->toJson());
    }
}
