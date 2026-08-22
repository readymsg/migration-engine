<?php

declare(strict_types=1);

namespace App\Data\SiteImport;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

// One diagnostic entry. Contract Part II "diagnostics":
//   "Tell us what you gave up on. This is how the contract improves —
//    a recurring diagnostic is a feature request for a new block.
//    Please use this generously."
//
// The Diagnostic channel is where every silent-loss surface in the
// engine becomes visible. Every ScrubIssue, every ConversionFailure,
// every unmappable IR intent, every widget gated out by orgType,
// every SVG dropped by the asset tokenizer — all become diagnostics
// so the reviewing admin (and Engineering) can see WHY the payload
// looks the way it does.
//
// severity taxonomy (Contract Part II):
//   info    — informational, no action expected
//   warning — a diminishment of the ingest (e.g. one asset failed
//             to fetch, or a scraped form was dropped)
//   error   — a rule violation; still emits the rest of the payload
//             (an `error` does not mean you should abandon the run)
final class Diagnostic extends Data
{
    /**
     * @param  string  $severity  info | warning | error
     * @param  string  $code  snake_case identifier — recurring codes become feature requests
     * @param  string  $message  human-readable single sentence
     * @param  Optional|string  $sourceUrl  the specific page/section this diagnostic refers to
     * @param  Optional|string  $droppedContent  short excerpt of dropped content if it's informative (rarely used)
     */
    public function __construct(
        public string $severity,
        public string $code,
        public string $message,
        public Optional|string $sourceUrl = new Optional,
        public Optional|string $droppedContent = new Optional,
    ) {}
}
