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

    // Deterministic gallery back-fill events (GalleryFiller pass).
    // Recorded in the same sidecar so all post-assembly deterministic
    // transformations share one audit trail.
    //   GalleryFilled       — target block augmented in-place with the
    //                         full source image list (recorded as an
    //                         informational entry so nothing changes
    //                         invisibly).
    //   GalleryFillFailure  — a source gallery couldn't be attached to
    //                         a target block AND couldn't be inserted
    //                         cleanly; images visibly missing.
    case GalleryFilled = 'gallery_filled';
    case GalleryFillFailure = 'gallery_fill_failure';

    // Deterministic SE-CDN URL rewrite events (AssetUrlRewriter pass).
    // Same audit sidecar as scrubbing / gallery-fill so every
    // post-assembly transformation shares one visibility surface.
    //   AssetUrlRewritten     — informational: a URL was swapped from
    //                           cdn*.sportngin.com to its S3 key.
    //   AssetRehostMissing    — visible failure: a live SE-CDN URL had
    //                           no matching AssetRef and stays live in
    //                           the emitted Puck. Zero-live-SE-
    //                           dependency invariant broken until fixed
    //                           — surfaced loud so it can't be silent.
    case AssetUrlRewritten = 'asset_url_rewritten';
    case AssetRehostMissing = 'asset_rehost_missing';

    // Deliberate hero image selection (HeroImageResolver pass).
    // Informational entry recording which candidate URL the resolver
    // picked as the Hero.background_image, and why. Always emitted
    // when a Hero block is present on a page — including "kept
    // block-fill's pick, no signal to override" cases — so the
    // choice is never invisible.
    case HeroImageChosen = 'hero_image_chosen';
}
