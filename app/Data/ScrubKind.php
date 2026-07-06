<?php

declare(strict_types=1);

namespace App\Data;

// What kind of scrub fired on a block during the post-assembly
// SePlatformBlockScrubber pass. Three categories match the three
// detection layers (see SePlatformBlockScrubber docblock):
//
//   - SePromoHref: block/child had an href pointing at unambiguously
//     SE-promo infrastructure (app store, SE solutions marketing).
//   - SePromoLabel: block/child's label exactly matches a closed
//     whitelist of known-promo phrases SE templates inject.
//   - StaleCountdown: block's body matches the zero-state countdown
//     pattern (multi-unit "Days / Hours / Minutes"), meaning it was a
//     live SE JS widget captured as static text after JS didn't run.
enum ScrubKind: string
{
    case SePromoHref = 'se_promo_href';
    case SePromoLabel = 'se_promo_label';
    case StaleCountdown = 'stale_countdown';
}
