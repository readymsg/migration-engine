<?php

declare(strict_types=1);

namespace App\Data;

// Disposition of a source element under BlockTypeAssigner + the
// element-level coverage reconciler:
//   - Captured   : content-bucket block; migrated data carried forward.
//   - Superseded : platform-bucket block; scraped equivalent discarded
//                  in favor of a live TeamLinkt block, OR a stale
//                  live-widget capture / SE-promo snippet removed by
//                  the scrubber.
//   - Excluded   : deliberately not migrated — source-platform chrome
//                  (SE-prelive nav, unsubscribe boilerplate, widget
//                  artefacts). Documented by ExclusionRules.
//   - OutOfScope : deliberately not migrated — content types the
//                  product has scoped OUT for this version (news
//                  article bodies, board / contact directories,
//                  sponsor strips). Named because they will eventually
//                  belong to a specific platform feature (NewsList,
//                  Executives, Sponsors). Documented by OutOfScopeRules.
//   - Unmapped   : no confident type match at block-typing time; fell
//                  back to Text. Surfaced — never swallowed.
enum AssignmentDisposition: string
{
    case Captured = 'captured';
    case Superseded = 'superseded';
    case Excluded = 'excluded';
    case OutOfScope = 'out_of_scope';
    case Unmapped = 'unmapped';
}
