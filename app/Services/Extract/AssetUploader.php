<?php

declare(strict_types=1);

namespace App\Services\Extract;

use App\Data\AssetRef;

// One side of the BUILD.md guardrail: assets pass as references, never
// binary payloads. Everything downstream consumes AssetRef, not bytes.
interface AssetUploader
{
    /**
     * Fetch the remote URL and store its body in S3.
     *
     * @param  string  $kind  path prefix, e.g. 'logos', 'scrapes'
     */
    public function putFromUrl(string $sourceUrl, string $orgId, string $kind): AssetRef;

    /**
     * Store raw content (e.g. a scrape JSON blob).
     *
     * @param  string  $kind  path prefix, e.g. 'logos', 'scrapes'
     * @param  string  $name  filename within $kind
     */
    public function putContent(string $content, string $mimeType, string $orgId, string $kind, string $name): AssetRef;
}
