<?php

declare(strict_types=1);

namespace App\Data;

enum ConversionStatus: string
{
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
}
