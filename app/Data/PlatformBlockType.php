<?php

declare(strict_types=1);

namespace App\Data;

// Concrete TeamLinkt Puck blocks that REGENERATE content from TeamLinkt's
// own data, replacing the source SE page entirely. A page classified as
// `platform_dynamic` carries one of these on its DecisionEntry so GENERATE
// knows which block to instantiate.
//
// Registration is intentionally NOT here — it's an external link to
// TeamLinkt's secure registration URL, not a block. See PLAN registration
// handling (kept as external + retarget note).
//
// Real-vs-placeholder block resolution is a GENERATE concern, not now.
enum PlatformBlockType: string
{
    case Schedule = 'schedule';
    case Scores = 'scores';
    case Standings = 'standings';
    case Roster = 'roster';
    case Teams = 'teams';
    case Divisions = 'divisions';
    case Contacts = 'contacts';

    // Reproduces SportsEngine's Calendar + NewsNode features as TeamLinkt
    // blocks. Routed deterministically from NavNode.node_type (Calendar /
    // NewsNode), never via the LLM. The rebuilt site reads from TeamLinkt's
    // own event + news data, NOT from SE — zero live SE dependency.
    case Calendar = 'calendar';
    case News = 'news';
}
