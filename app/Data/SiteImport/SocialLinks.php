<?php

declare(strict_types=1);

namespace App\Data\SiteImport;

use Spatie\LaravelData\Data;

// The six social platforms the contract's SiteSettings.socialLinks
// object recognises. Each is a URL or `""` (empty = not shown).
// Contract Part II "What you may set on `site`" socialLinks row.
final class SocialLinks extends Data
{
    public function __construct(
        public string $facebook = '',
        public string $twitter = '',
        public string $instagram = '',
        public string $tiktok = '',
        public string $youtube = '',
        public string $linkedin = '',
    ) {}
}
