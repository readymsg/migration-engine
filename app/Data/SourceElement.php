<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// One discrete element extracted from a page's source markdown. The
// matchable atom of the coverage report: each SourceElement is
// reconciled against the rebuilt Puck page as CAPTURED (content
// survived), SUPERSEDED (replaced by a Platform block or removed by
// the SE-content scrubber), or DROPPED (present in source, absent
// from rebuild — the failure channel).
//
// `kind` matches SourceElementCounter's taxonomy:
//   heading | prose | image | link | document | embed | contact_detail | table
//
// `content` is what the reconciler matches against Puck block props:
//   - heading/prose/table cells → normalized text
//   - image/link/document       → resolved URL (src or href)
//   - contact_detail            → email or phone string
//
// `snippet` is what the report renders — bounded human display of the
// element in its source form (e.g. the full `[label](href)` for a link).
final class SourceElement extends Data
{
    public function __construct(
        public string $kind,
        public string $content,
        public string $snippet,
    ) {}
}
