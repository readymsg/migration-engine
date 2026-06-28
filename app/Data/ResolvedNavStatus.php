<?php

declare(strict_types=1);

namespace App\Data;

// Per-NavItem resolution outcome at draft-landing time. See
// ResolvedNavItem for case meanings.
enum ResolvedNavStatus: string
{
    case Resolved = 'resolved';
    case UnmatchedExternal = 'unmatched_external';
    case Unresolved = 'unresolved';
}
