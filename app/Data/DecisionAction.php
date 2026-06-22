<?php

declare(strict_types=1);

namespace App\Data;

// Disposition for a single page in the planner's classify step.
// Drops are reversible — `park` marks a page as deferred, never deleted.
enum DecisionAction: string
{
    case Keep = 'keep';
    case Merge = 'merge';
    case Drop = 'drop';
    case Park = 'park';
    case Dynamic = 'dynamic';

    // Page whose content TeamLinkt regenerates from its own data via a
    // PlatformBlockType (Schedule, Scores, Standings, Roster, Teams,
    // Divisions, Contacts, Calendar, News). Distinct from Keep: the source
    // page is NOT scraped — GENERATE drops in the platform block instead.
    case PlatformDynamic = 'platform_dynamic';

    // Descendant of a platform_dynamic node — represented in full by the
    // ancestor's block, so we don't scrape, classify, or independently
    // rebuild it. Absent from nav + kept_pages, present in the ledger
    // (with the subsuming parent's label) so a reviewer can promote one
    // back if the block doesn't cover their case.
    case Subsumed = 'subsumed';
}
