<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\EmitPreviewFixture;
use App\Data\ConversionStatus;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Feature test for the throwaway preview slice (BUILD.md step 7).
//
// Validates the PHP-testable surface:
//   1. The artisan emit-preview-fixture command runs cleanly and writes
//      a ConversionResult JSON the bundle can consume.
//   2. GET /preview/tbirdhoops returns 200 and serves the Vite bundle host.
//   3. GET /api/preview/tbirdhoops/site returns JSON with the
//      ConversionResult shape the bundle expects (page_map keyed by
//      slug, nav array, status enum).
//
// The bundle's RENDERING is not PHP-testable — for throwaway preview
// code, eyeballing in the browser is the validation (per CLAUDE.md UI
// discipline).
final class PreviewRouteTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = storage_path('app/public/preview/tbirdhoops.json');

        // Clean slate every run so we test the artisan command as part
        // of the integration. The command is fast (no LLM, no network)
        // so re-emitting per test is fine.
        if (is_file($this->fixturePath)) {
            unlink($this->fixturePath);
        }
    }

    #[Test]
    public function emit_preview_fixture_command_writes_consumable_json(): void
    {
        $exit = Artisan::call(EmitPreviewFixture::class);
        $this->assertSame(0, $exit, 'engine:emit-preview-fixture should exit success');

        $this->assertFileExists($this->fixturePath);
        $raw = file_get_contents($this->fixturePath);
        $this->assertIsString($raw);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertContractShape($decoded);
    }

    #[Test]
    public function preview_show_returns_200_with_bundle_host(): void
    {
        Artisan::call(EmitPreviewFixture::class);

        $response = $this->get('/preview/tbirdhoops');
        $response->assertStatus(200);
        $response->assertSee('preview-root', escape: false);
        $response->assertSee('data-slug="tbirdhoops"', escape: false);
    }

    #[Test]
    public function preview_show_returns_404_with_actionable_message_when_fixture_missing(): void
    {
        // Intentionally do NOT run the artisan command.
        $response = $this->get('/preview/tbirdhoops');
        $response->assertStatus(404);
        $response->assertSee('engine:emit-preview-fixture', escape: false);
    }

    #[Test]
    public function preview_site_returns_conversion_result_json_with_expected_shape(): void
    {
        Artisan::call(EmitPreviewFixture::class);

        $response = $this->get('/api/preview/tbirdhoops/site');
        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/json');

        /** @var array<string, mixed> $body */
        $body = $response->json();
        $this->assertContractShape($body);
    }

    /**
     * Asserts the JSON shape the React bundle depends on.
     *
     * @param  array<string, mixed>  $body
     */
    private function assertContractShape(array $body): void
    {
        foreach ([
            'conversion_id',
            'org_id',
            'page_map',
            'nav',
            'failures',
            'block_issues_by_slug',
            'status',
        ] as $key) {
            $this->assertArrayHasKey($key, $body, "ConversionResult JSON missing '{$key}'");
        }

        // status is one of the ConversionStatus enum values.
        $this->assertContains(
            $body['status'],
            array_map(fn (ConversionStatus $s): string => $s->value, ConversionStatus::cases()),
            'status must be a ConversionStatus enum value',
        );

        // page_map is keyed by slug, value is Puck {content, root, zones}.
        $this->assertIsArray($body['page_map']);
        $this->assertNotSame([], $body['page_map'], 'tbirdhoops fixture has FilledPages — page_map must be non-empty');
        foreach ($body['page_map'] as $slug => $page) {
            $this->assertIsString($slug);
            $this->assertIsArray($page);
            $this->assertArrayHasKey('content', $page, "page '{$slug}' missing 'content'");
            $this->assertArrayHasKey('root', $page, "page '{$slug}' missing 'root'");
            $this->assertIsArray($page['content']);
            foreach ($page['content'] as $block) {
                $this->assertIsArray($block);
                $this->assertArrayHasKey('type', $block, 'every Puck block carries a type discriminator');
                $this->assertArrayHasKey('props', $block, 'every Puck block carries a props bag');
            }
        }

        // nav is a list of ResolvedNavItem dicts.
        $this->assertIsArray($body['nav']);
        foreach ($body['nav'] as $nav) {
            $this->assertIsArray($nav);
            $this->assertArrayHasKey('label', $nav);
            $this->assertArrayHasKey('page_slug', $nav);
            $this->assertArrayHasKey('order', $nav);
            $this->assertArrayHasKey('status', $nav);
            $this->assertContains($nav['status'], ['resolved', 'unmatched_external', 'unresolved']);
        }

        // tbirdhoops is offline-replay → status is partial (one
        // Unresolved nav for Unsubscribe — the offline/online gap
        // CLAUDE.md documents). If this regresses, the lander or PLAN
        // changed shape under the preview.
        $this->assertSame('partial', $body['status']);
        $this->assertGreaterThanOrEqual(1, count($body['failures']));
    }
}
