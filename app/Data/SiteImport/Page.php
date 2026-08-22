<?php

declare(strict_types=1);

namespace App\Data\SiteImport;

use Spatie\LaravelData\Data;

// One page in the pages[] array. Contract Part II "`pages`".
//
// SLUG RULES (Contract Part II "Slug rules"):
//   - EXACTLY one page must have slug: "" — the homepage. If the
//     scrape has no obvious homepage, pick the page the site's own
//     navigation links to most often, falling back to the shallowest
//     URL, and set its slug to "". Never emit a payload with no
//     homepage; never invent an empty one.
//   - Slugs must be UNIQUE per site, compared case-insensitively.
//     Duplicates reject the whole import.
//   - `view` is a RESERVED top-level slug — `/view/team/{id}`,
//     `/view/game/{id}` etc. win over page lookup. No slug may be
//     `view` or start with `view/`.
//   - lowercase, hyphens, no leading/trailing `/`, no file extensions.
//     Strip .html, .php, index, and query strings from scraped URLs.
//   - Nest with `/` in the slug AND set parentId. The slug drives the
//     URL; parentId drives the menu tree. Keep them consistent or the
//     nav will disagree with the address bar.
//
// id is PAYLOAD-LOCAL only — a join key for parentId. Never stored;
// ingest mints a UUID per page. Conventional value for the homepage
// is "home" but any unique identifier works.
//
// pageType is NOT importable and NOT modelled here — division-template
// pages are an authoring decision an admin makes deliberately.
final class Page extends Data
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $title,
        // Another page's id in the same payload, or null for top level.
        // A parentId that resolves to nothing REJECTS the import — the
        // ingest will not silently reparent to root.
        public ?string $parentId,
        public int $navOrder,
        public bool $showInNav,
        public PageData $data,
    ) {}
}
