<?php

declare(strict_types=1);

namespace App\Services\Extract;

// Fetches an HTML page and reports the post-redirect URL. Separate seam from
// RootNavFetcher because the homepage HTML is the only place brand assets +
// the starting page_node_id can be found — neither lives in the rootNav JSON.
interface HtmlFetcher
{
    public function fetch(string $url): HtmlFetchResult;
}
