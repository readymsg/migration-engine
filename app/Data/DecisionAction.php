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
}
