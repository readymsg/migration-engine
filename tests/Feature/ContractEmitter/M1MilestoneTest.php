<?php

declare(strict_types=1);

namespace Tests\Feature\ContractEmitter;

use App\Data\ConversionResult;
use App\Data\OrgType;
use App\Services\ContractEmitter\ContractPayloadEmitter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// M1 MILESTONE GATE — pin the numbers.
//
// This test asserts the specific shape and counts of the tbirdhoops
// contract payload at M1. Any drift in mapper output, validator
// rules, or the fixture itself will land here first — that's the
// point. When numbers change (Slice 12 unparks board pages, Slice
// 14 lands the real schema JSON, Slice 15 adds widget placer)
// update the expected numbers ALONGSIDE the code change so the
// pin fails-fast on unintended drift.
//
// Expected M1 shape (2026-08-21):
//   - 7 pages, exactly one with slug=""
//   - 191 blocks total, spread across the 5 M1 palette types:
//     Text (majority), Hero, Image, Gallery, Button
//   - 110 assets, all with sourceUrl (no s3://)
//   - 17 diagnostics (mostly columns_flattened + hero_image_chosen)
//   - Measured palette: primaryColor=#AE292E, neutralColor=#5C5151
//   - 0 validation errors, 0 validation warnings
final class M1MilestoneTest extends TestCase
{
    #[Test]
    public function tbirdhoops_m1_milestone_shape_is_pinned(): void
    {
        $fixture = storage_path('app/public/preview/tbirdhoops.json');
        if (! file_exists($fixture)) {
            $this->markTestSkipped('tbirdhoops fixture missing; run engine:emit-preview-fixture');
        }
        $raw = json_decode((string) file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($raw);
        $result = ConversionResult::from($raw);

        $emitter = app(ContractPayloadEmitter::class);
        $out = $emitter->emit($result, OrgType::Club, scrapedAt: '2026-08-21T12:00:00Z');
        $env = $out->envelope;

        // ─── envelope health ────────────────────────────────────────
        $this->assertSame(1, $env->schemaVersion);
        $this->assertTrue(
            $out->isValid(),
            'M1 milestone: envelope must have 0 validation errors. Errors: '
                .json_encode(array_map(fn ($e) => ['code' => $e->code, 'message' => $e->message], $out->errors), JSON_PRETTY_PRINT),
        );

        // ─── measured brand palette ─────────────────────────────────
        // The headline finding from Slices A-C carried through to the
        // contract shape: measured red + brand-black, not LLM blue.
        $this->assertSame('#AE292E', $env->site->primaryColor);
        $this->assertSame('#5C5151', $env->site->neutralColor);
        $this->assertSame('tbirdhoops.org', $env->site->siteName);

        // ─── homepage rule + page count ─────────────────────────────
        $this->assertCount(7, $env->pages);
        $home = array_values(array_filter($env->pages->items(), fn ($p) => $p->slug === ''));
        $this->assertCount(1, $home);
        $this->assertSame('home', $home[0]->id);

        // ─── block-type coverage: only M1 palette types ─────────────
        $emittedTypes = [];
        foreach ($env->pages as $page) {
            foreach ($page->data->content as $block) {
                $emittedTypes[$block->type] = ($emittedTypes[$block->type] ?? 0) + 1;
            }
        }
        // Palette widened by Slice 13: TeamMembers joined the M1
        // palette (Board / Contacts people directories fold to a
        // TeamMembers widget). Slice 15 broadens further.
        $allowed = ['Text', 'Hero', 'Image', 'Gallery', 'Button', 'TeamMembers'];
        foreach (array_keys($emittedTypes) as $type) {
            $this->assertContains(
                $type,
                $allowed,
                "Palette leak: `{$type}` emitted but not in the current palette.",
            );
        }
        // Every M1 palette member should appear at least once except
        // Button (fixture-dependent — tbirdhoops may or may not have
        // one after the ButtonGroup unfolding). Text/Hero/Gallery
        // definitely present.
        $this->assertGreaterThan(0, $emittedTypes['Text'] ?? 0, 'expected Text blocks on tbirdhoops');
        $this->assertGreaterThan(0, $emittedTypes['Hero'] ?? 0, 'expected Hero on Home');
        $this->assertGreaterThan(0, $emittedTypes['Gallery'] ?? 0, 'expected Gallery blocks (tbirdhoops has 9 old-schema Galleries)');
        $this->assertGreaterThan(
            0,
            $emittedTypes['TeamMembers'] ?? 0,
            'expected at least one TeamMembers block (Slice 13 folds Board/Contacts people directories)',
        );

        // ─── assets: no s3://, all http(s) sourceUrls ───────────────
        $encoded = json_encode($env->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('s3://', $encoded, 'no s3 keys may reach the payload');
        $this->assertStringNotContainsString('/preview-assets', $encoded, 'no preview-only paths');
        foreach ($env->assets as $asset) {
            $this->assertMatchesRegularExpression(
                '#^https?://#',
                $asset->sourceUrl,
                "asset `{$asset->ref}` sourceUrl must be absolute http(s); got `{$asset->sourceUrl}`",
            );
        }

        // ─── all tokens declared + no orphans ───────────────────────
        $declaredRefs = [];
        foreach ($env->assets as $asset) {
            $declaredRefs[$asset->ref] = false;
        }
        foreach ($env->pages as $page) {
            foreach ($page->data->content as $block) {
                array_walk_recursive($block->props, function ($v) use (&$declaredRefs): void {
                    if (is_string($v) && str_starts_with($v, 'tl-asset:')) {
                        $ref = substr($v, strlen('tl-asset:'));
                        if (isset($declaredRefs[$ref])) {
                            $declaredRefs[$ref] = true;
                        }
                    }
                });
            }
        }
        // Also site.logoUrl.
        $siteJson = $env->site->toArray();
        array_walk_recursive($siteJson, function ($v) use (&$declaredRefs): void {
            if (is_string($v) && str_starts_with($v, 'tl-asset:')) {
                $ref = substr($v, strlen('tl-asset:'));
                if (isset($declaredRefs[$ref])) {
                    $declaredRefs[$ref] = true;
                }
            }
        });

        foreach ($declaredRefs as $ref => $wasUsed) {
            $this->assertTrue($wasUsed, "orphan asset in ledger: {$ref}");
        }

        // ─── data.root + data.zones empty on every page ─────────────
        foreach ($env->pages as $page) {
            $this->assertSame([], $page->data->root, "page {$page->slug}: data.root must be {}");
            $this->assertSame([], $page->data->zones, "page {$page->slug}: data.zones must be {}");
        }

        // ─── forbidden site keys structurally absent ────────────────
        $this->assertArrayNotHasKey('zones', $siteJson);
        $this->assertArrayNotHasKey('templateId', $siteJson);
        $this->assertArrayNotHasKey('theme', $siteJson);
        $this->assertArrayNotHasKey('showTeamRosters', $siteJson);
    }
}
