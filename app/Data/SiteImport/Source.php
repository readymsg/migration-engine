<?php

declare(strict_types=1);

namespace App\Data\SiteImport;

use Spatie\LaravelData\Data;

// Provenance sub-object of the Envelope. Informational — TeamLinkt
// logs it and shows the counts to support, but no ingest rule keys
// off any of these fields. Site Import Contract Part II "The
// envelope" ("Provenance. Purely informational...").
final class Source extends Data
{
    public function __construct(
        public string $url,
        // ISO 8601 UTC — the moment the scrape completed, not the
        // moment the payload was emitted.
        public string $scrapedAt,
        public int $pagesDiscovered,
        public int $pagesMapped,
    ) {}
}
