<?php

declare(strict_types=1);

namespace App\Data\SiteImport;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

// Branding + site-level settings. Every key is optional — Site Import
// Contract Part II "What you may set on `site`" — so this class uses
// Spatie's Optional sentinel so absent props are OMITTED from JSON,
// not serialised as null. Absent === "leave the default in place".
//
// FORBIDDEN keys (Part II "Forbidden keys" table) that this class
// deliberately does NOT expose, and that the emitter refuses to
// carry — enforced structurally by omission from this DTO:
//   - zones            (chrome comes from the template)
//   - templateId, templateChosen, templateSettings
//   - theme            (Radix overrides are template-owned)
//   - showTeamRosters  (privacy setting, never machine-decided)
//
// The two highest-value fields for translation are primaryColor +
// neutralColor — our LogoPaletteExtractor's measured `primary` maps
// to primaryColor and measured `text` maps to neutralColor. secondary
// / accent / background from the extractor have no contract slot
// and are preview-only debug output.
final class SiteSettings extends Data
{
    /**
     * @param  Optional|string  $logoUrl  tl-asset:<ref> or absolute URL
     * @param  Optional|string  $favicon  tl-asset:<ref> or absolute URL
     * @param  Optional|string  $primaryColor  hex string — measured brand primary (highest-value field)
     * @param  Optional|string  $neutralColor  hex string — measured brand text; primary text and borders
     * @param  Optional|string  $siteBackground  hex string
     * @param  Optional|string  $siteBackgroundSize  cover | contain | auto
     * @param  Optional|string  $siteBackgroundPosition  center | top | bottom | left | right
     * @param  Optional|string  $siteBackgroundRepeat  no-repeat | repeat | repeat-x | repeat-y
     * @param  Optional|string  $pageBackground  hex string — inner page surface, usually white
     * @param  Optional|string  $contactEmail  must be a valid email address or empty; malformed blocks publish
     */
    public function __construct(
        public Optional|string $siteName = new Optional,
        public Optional|string $logoUrl = new Optional,
        public Optional|string $favicon = new Optional,
        public Optional|string $primaryColor = new Optional,
        public Optional|string $neutralColor = new Optional,
        public Optional|string $headerColor = new Optional,
        public Optional|string $headerTextColor = new Optional,
        public Optional|string $headerBackgroundImage = new Optional,
        public Optional|string $siteBackground = new Optional,
        public Optional|string $siteBackgroundImage = new Optional,
        public Optional|string $siteBackgroundSize = new Optional,
        public Optional|string $siteBackgroundPosition = new Optional,
        public Optional|string $siteBackgroundRepeat = new Optional,
        public Optional|string $pageBackground = new Optional,
        public Optional|string $footerCopyright = new Optional,
        public Optional|string $contactEmail = new Optional,
        public Optional|SocialLinks $socialLinks = new Optional,
    ) {}
}
