<?php

declare(strict_types=1);

namespace Tests\Unit\ContractEmitter;

use App\Data\DiagnosticsSidecar;
use App\Data\SiteImport\Diagnostic;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// LOAD-BEARING TEST — the promise called out in the class docblock:
// "the sidecar's `diagnostics` list has the SAME LENGTH as what used
// to ship inside the envelope."
//
// v2 moved diagnostics out of envelope.diagnostics onto the sidecar
// DTO. If a future refactor drops entries silently (buckets that
// don't cover all severities, category exclusion, etc.) this test
// fires. Concrete assertion: an input list of N Diagnostics builds a
// sidecar whose `diagnostics->count() === N` AND whose per-severity
// tally sums to N AND whose per-code tally sums to N. Nothing lost.
final class DiagnosticsSidecarCountEqualityTest extends TestCase
{
    #[Test]
    public function sidecar_preserves_every_diagnostic_from_the_input_list(): void
    {
        // A representative mix — the six shapes that used to appear
        // in envelope.diagnostics: palette (info), page-tree rename
        // (warning), mapper drop (error), scrubber (warning), block-
        // fill failure (error), plus a plain info entry. Six inputs.
        $inputs = [
            new Diagnostic('info', 'palette_primary_from_measured', 'primary #AE292E measured from logo bytes'),
            new Diagnostic('warning', 'reserved_slug_renamed', 'renamed `view` → `page-view`'),
            new Diagnostic('warning', 'slug_collision_disambiguated', 'renamed `about` → `about-2`'),
            new Diagnostic('error', 'unmappable_block_type', 'unknown IR block `stat_grid`'),
            new Diagnostic('warning', 'assembly_normalize', 'coerced Hero.height 520 → 400'),
            new Diagnostic('warning', 'se_promo_href', 'dropped ButtonGroup with SE app-store links'),
        ];

        $sidecar = DiagnosticsSidecar::build(
            diagnostics: $inputs,
            schemaSha256: str_repeat('a', 64),
            schemaVersion: 1,
            sourceUrl: 'https://example.com',
            pagesDiscovered: 7,
            pagesMapped: 7,
        );

        // Primary invariant: same count.
        $this->assertSame(count($inputs), $sidecar->diagnostics->count());

        // Per-severity tally sums to N (no bucket lost).
        $severitySum = array_sum($sidecar->coverage_by_severity);
        $this->assertSame(count($inputs), $severitySum);

        // Per-code tally sums to N (no code lost).
        $codeSum = array_sum($sidecar->coverage_by_code);
        $this->assertSame(count($inputs), $codeSum);

        // Explicit severity counts — 1 info, 4 warnings, 1 error.
        $this->assertSame(1, $sidecar->coverage_by_severity['info']);
        $this->assertSame(4, $sidecar->coverage_by_severity['warning']);
        $this->assertSame(1, $sidecar->coverage_by_severity['error']);

        // Ordering preserved (v1 envelope.diagnostics ordering
        // matters to reviewers grepping across payloads).
        $stored = $sidecar->diagnostics->toArray();
        $this->assertSame('palette_primary_from_measured', $stored[0]['code']);
        $this->assertSame('se_promo_href', $stored[5]['code']);
    }

    #[Test]
    public function empty_input_yields_a_valid_zero_count_sidecar(): void
    {
        $sidecar = DiagnosticsSidecar::build(
            diagnostics: [],
            schemaSha256: str_repeat('b', 64),
            schemaVersion: 1,
            sourceUrl: 'https://example.com',
            pagesDiscovered: 0,
            pagesMapped: 0,
        );
        $this->assertSame(0, $sidecar->diagnostics->count());
        $this->assertSame(0, array_sum($sidecar->coverage_by_severity));
        $this->assertSame(0, array_sum($sidecar->coverage_by_code));
    }

    #[Test]
    public function schema_sha256_and_metadata_are_stamped(): void
    {
        $sha = str_repeat('c', 64);
        $sidecar = DiagnosticsSidecar::build(
            diagnostics: [],
            schemaSha256: $sha,
            schemaVersion: 1,
            sourceUrl: 'https://example.com',
            pagesDiscovered: 3,
            pagesMapped: 2,
        );
        $this->assertSame($sha, $sidecar->schema_sha256);
        $this->assertSame(1, $sidecar->schema_version);
        $this->assertSame('https://example.com', $sidecar->source_url);
        $this->assertSame(3, $sidecar->pages_discovered);
        $this->assertSame(2, $sidecar->pages_mapped);
    }
}
