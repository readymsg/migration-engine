<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// Draft-landing's reconciled nav: one entry per SitePlan.nav item, with
// the page_slug rewritten from the planner's label-derived form to the
// PageSlug::of() form that the page_map keys actually use. Carries the
// resolution status so a reviewer (and SCORE & LOG) can distinguish:
//
//   - Resolved        — page_slug joins into a page_map key. The nav
//                       link will work on the rebuilt site.
//   - UnmatchedExternal — joined to a kept_page, but that page produces
//                       no PuckOutput (kind=external — LinkNode/toolsLink).
//                       Expected on real SE sites; nav-layer concern,
//                       not a draft-landing failure.
//   - Unresolved      — couldn't join NavItem.label back to a depth-0
//                       kept_page at all. Unreachable under current
//                       planner invariants (planner copies label verbatim
//                       from InventoryPage into NavItem). If this ever
//                       fires, the planner shape has drifted; logged as
//                       a draft-landing ConversionFailure too.
final class ResolvedNavItem extends Data
{
    public function __construct(
        public string $label,
        public string $page_slug,
        public int $order,
        public ResolvedNavStatus $status,
        public ?string $note = null,
    ) {}
}
