<?php

declare(strict_types=1);

namespace App\Services\Extract;

use App\Data\Manifest;

// Stage 1 (INGEST) seam. v1 only has SportNginExtractor behind this;
// Sports Connect lands later as a second class behind the same interface.
interface Extractor
{
    public function extract(string $url): Manifest;
}
