<?php

declare(strict_types=1);

namespace App\Data;

use App\Data\SiteImport\Diagnostic;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

// Contract v2 removed the `diagnostics[]` channel from the envelope
// (the whole $def is gone; `additionalProperties: false` now rejects
// it). Nothing about the engine's silent-loss surfaces changed — every
// scrub, park, palette fallback, stage failure still needs to be
// visible to a reviewer. In v2 they land HERE, on a per-conversion
// sidecar persisted alongside the envelope. The transport story
// (HTTP endpoint to serve this) is deferred pending a Scott
// conversation (see the last-report v2 orientation §5).
//
// LOAD-BEARING PROPERTY (enforced by
// DiagnosticsSidecarCountEqualityTest): the sidecar's `diagnostics`
// list has the SAME LENGTH as what used to ship inside the envelope.
// Nothing was lost by moving the channel out — every entry that would
// have been in `envelope.diagnostics` is in `sidecar.diagnostics`.
// The `coverage` view is a derived summary; the flat list is the
// canonical stream.
//
// Ordering matches the old envelope.diagnostics order: extras first
// (page-tree, mapper, site-settings — grouped by producing stage),
// then failures (errors), then block issues, then scrub issues.
// Preserving order matters for reviewer grepability across payloads.
final class DiagnosticsSidecar extends Data
{
    /**
     * @param  DataCollection<int, Diagnostic>  $diagnostics  canonical flat stream; same order as v1's envelope.diagnostics
     * @param  array<string, int>  $coverage_by_severity  {info, warning, error}
     * @param  array<string, int>  $coverage_by_code  code => count; recurring codes surface here
     */
    public function __construct(
        public string $schema_sha256,
        public int $schema_version,
        public string $generated_at,
        public string $source_url,
        public int $pages_discovered,
        public int $pages_mapped,
        #[DataCollectionOf(Diagnostic::class)]
        public DataCollection $diagnostics,
        public array $coverage_by_severity,
        public array $coverage_by_code,
    ) {}

    /**
     * Build a sidecar from a Diagnostic list + schema hash + source
     * meta. The coverage tallies are derived from the list, so the
     * count-equality property is structural — not a manual counter
     * you can forget to update.
     *
     * @param  array<int, Diagnostic>  $diagnostics
     */
    public static function build(
        array $diagnostics,
        string $schemaSha256,
        int $schemaVersion,
        string $sourceUrl,
        int $pagesDiscovered,
        int $pagesMapped,
        ?string $generatedAt = null,
    ): self {
        $bySeverity = ['info' => 0, 'warning' => 0, 'error' => 0];
        $byCode = [];
        foreach ($diagnostics as $d) {
            $sev = $d->severity;
            if (! isset($bySeverity[$sev])) {
                $bySeverity[$sev] = 0;
            }
            $bySeverity[$sev]++;
            $byCode[$d->code] = ($byCode[$d->code] ?? 0) + 1;
        }
        ksort($byCode);

        return new self(
            schema_sha256: $schemaSha256,
            schema_version: $schemaVersion,
            generated_at: $generatedAt ?? gmdate('Y-m-d\TH:i:s\Z'),
            source_url: $sourceUrl,
            pages_discovered: $pagesDiscovered,
            pages_mapped: $pagesMapped,
            diagnostics: new DataCollection(Diagnostic::class, $diagnostics),
            coverage_by_severity: $bySeverity,
            coverage_by_code: $byCode,
        );
    }
}
