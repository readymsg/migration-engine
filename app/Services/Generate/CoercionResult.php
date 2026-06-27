<?php

declare(strict_types=1);

namespace App\Services\Generate;

// One pass of BlockCoercer::coerce() on a single block. Either the
// block survives (coerced_props non-null, dropped=false, optionally
// with some non-fatal issues recorded) OR it's dropped (coerced_props
// null, dropped=true, drop_reason populated, the drop itself is
// already in issues with coercion=Drop).
final class CoercionResult
{
    /**
     * @param  array<string, mixed>|null  $coerced_props
     * @param  array<int, CoercerIssue>  $issues
     */
    public function __construct(
        public readonly ?array $coerced_props,
        public readonly array $issues,
        public readonly bool $dropped,
        public readonly string $drop_reason = '',
    ) {}
}
