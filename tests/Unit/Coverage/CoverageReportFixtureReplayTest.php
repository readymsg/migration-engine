<?php

declare(strict_types=1);

namespace Tests\Unit\Coverage;

use App\Data\ConversionResult;
use App\Services\Coverage\CoverageReconciler;
use App\Services\Coverage\CoverageReport;
use App\Services\Coverage\SourceElementCounter;
use App\Services\Generate\BlockTypeAssigner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Runs the coverage report against the ACTUAL preview fixture written
// by engine:emit-preview-fixture (tbirdhoops end-to-end). No LLM, no
// network — the fixture is committed to the repo.
//
// Assertions are INVARIANTS about the shape of the artifact, not exact
// counts. Fixture regeneration must not need this test rewritten.
final class CoverageReportFixtureReplayTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../../storage/app/public/preview/tbirdhoops.json';

    #[Test]
    public function tbirdhoops_conversion_produces_a_populated_coverage_report(): void
    {
        $this->assertFileExists(self::FIXTURE, 'run engine:emit-preview-fixture first');
        $raw = file_get_contents(self::FIXTURE);
        $this->assertIsString($raw);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        $result = ConversionResult::from($decoded);

        $pageTitles = [];
        $pageMarkdown = [];
        foreach ($result->page_map as $slug => $payload) {
            $root = is_array($payload['root'] ?? null) ? $payload['root'] : [];
            $pageTitles[$slug] = is_string($root['title'] ?? null) ? $root['title'] : $slug;
            $pageMarkdown[$slug] = ''; // scrapes intentionally not loaded here (that's the command's job)
        }

        $report = new CoverageReport(new BlockTypeAssigner, new SourceElementCounter, new CoverageReconciler);
        $md = $report->render(
            pageMap: $result->page_map,
            pageTitles: $pageTitles,
            pageMarkdown: $pageMarkdown,
            scrubIssuesBySlug: $result->scrub_issues_by_slug,
        );

        // Structural invariants — do not pin to counts that will drift.
        $this->assertStringContainsString('# Migration coverage report', $md);
        $this->assertStringContainsString('Home', $md);
        $this->assertStringContainsString('About Us', $md);
        $this->assertStringContainsString('#### CAPTURED (elements)', $md);
        $this->assertStringContainsString('#### SUPERSEDED (elements)', $md);
        $this->assertStringContainsString('#### DROPPED (elements)', $md);
        $this->assertStringContainsString('## Site summary — content coverage (element-level)', $md);
        $this->assertStringContainsString('## Site summary — block-type assignment (secondary metric)', $md);

        // Every rebuilt page MUST appear as a per-page section.
        foreach ($result->page_map as $slug => $payload) {
            $root = is_array($payload['root'] ?? null) ? $payload['root'] : [];
            $title = is_string($root['title'] ?? null) ? $root['title'] : $slug;
            $this->assertStringContainsString("### {$title}", $md, "expected per-page section for '{$title}'");
        }
    }
}
