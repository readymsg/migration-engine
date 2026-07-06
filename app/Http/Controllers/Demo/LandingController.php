<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Services\Conversion\ConversionCostGuard;
use Illuminate\Contracts\View\View;

// Landing page for the hosted demo. Serves a single Blade view with:
//   - A URL input pre-populated with a curated tbirdhoops example
//     (the most-fully-rendered club — leagues like cjfl show placeholder
//     platform blocks which is honest but less impressive as a lead demo).
//   - A row of allowlist chips the visitor can click to prefill.
//   - Convert button → vanilla-JS POST to /api/conversions.
//   - Polling display: stage label + N-of-M during block-fill + elapsed.
//   - Redirect to /preview/conv-<id> on Complete/Partial.
//   - Cleanly-shown failure_reason on Failed.
//
// Demo token + allowlist are embedded server-side into the page (visible
// in the HTML source — this is the "public token" model, deliberately
// not a real secret; the ALLOWLIST + daily budget + concurrency lock
// bound the cost that a leaked token could cause).
final class LandingController extends Controller
{
    public function __construct(
        private readonly ConversionCostGuard $costGuard,
    ) {}

    public function show(): View
    {
        // The lead example — the URL prefilled in the input on first
        // load. tbirdhoops chosen because it's a club (mostly static
        // content, renders most fully) rather than a league (more
        // platform-block placeholders). See CLAUDE.md's Hosted-demo
        // section for the rationale.
        $leadUrl = 'https://www.tbirdhoops.org/';

        // Reorder allowlist so the lead is first, others follow.
        $rawAllowlist = $this->costGuard->rawAllowlistForFrontend();
        $ordered = array_values(array_unique(array_filter(array_merge(
            [$leadUrl],
            $rawAllowlist,
        ))));

        // Filter to allowlist-only if allowlist is configured (hosted
        // demo). If empty (dev/local), fall through with just the lead.
        if ($rawAllowlist !== []) {
            $ordered = array_values(array_filter($ordered, static fn (string $u): bool => in_array($u, $rawAllowlist, true) || $u === $rawAllowlist[0]));
            // Ensure lead URL is on the allowlist for the display
            // (defensive — if operator forgot to include tbirdhoops in
            // the allowlist, use the first allowlist entry instead).
            if (! in_array($leadUrl, $rawAllowlist, true)) {
                $ordered = $rawAllowlist;
                $leadUrl = $rawAllowlist[0];
            }
        }

        return view('landing', [
            'demo_token' => (string) config('services.conversion.demo_token', ''),
            'lead_url' => $leadUrl,
            'allowlist' => $ordered,
        ]);
    }
}
