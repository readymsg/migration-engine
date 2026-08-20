<?php

declare(strict_types=1);

namespace App\Data;

// Three disjoint buckets a TeamLinkt block can belong to. Determines how
// the migration engine treats the block:
//   - Content  : block carries migrated data from the source page.
//   - Platform : block reads live from TeamLinkt's own database. Scraped
//                data is discarded; the source element is marked
//                SUPERSEDED in the coverage report.
//   - Form     : structure only, never carries submissions.
enum TeamlinktBlockBucket: string
{
    case Content = 'content';
    case Platform = 'platform';
    case Form = 'form';
}
