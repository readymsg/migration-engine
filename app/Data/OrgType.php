<?php

declare(strict_types=1);

namespace App\Data;

// Organization category. Gates which contract blocks the org may
// receive per the Site Import Contract Part II "Org types":
//   - Standings/Scores/Schedule/ScoresSchedule/Statistics/Suspensions
//     /TeamRoster/Teams  → league | high_school | association only
//   - EventMarquee                  → those three plus club
//   - Everything else               → all six
//
// Emitting a gated block for a wrong orgType is an ingest ERROR (not
// a warning) — the block would not even appear in the org's palette,
// so a silently-dropped one on ingest would be a full data-loss.
// The contract emitter surfaces such attempts as diagnostics instead.
enum OrgType: string
{
    case Club = 'club';
    case Association = 'association';
    case League = 'league';
    case HighSchool = 'high_school';
    case Civic = 'civic';
    case MultiLocation = 'multi_location';
}
