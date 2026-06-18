<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

final class ComponentDefinition extends Data
{
    /**
     * @param  array<string, FieldDefinition>  $fields  keyed by prop name
     */
    public function __construct(
        public string $type,
        public array $fields,
    ) {}
}
