<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

// One Puck field shape inside a ComponentDefinition. Today this mirrors the hand-written
// default-Puck schema; later it'll mirror the real fetched export from the product.
final class FieldDefinition extends Data
{
    /**
     * @param  array<string, FieldDefinition>|null  $object_fields  set when $type is 'object' or 'array'
     * @param  array<int, string>|null  $options  set when $type is 'select' or 'radio'
     */
    public function __construct(
        // Puck field type. TODO: enum once the real product schema lands; current values:
        // 'text' | 'textarea' | 'number' | 'select' | 'radio' | 'image' | 'object' | 'array'.
        public string $type,
        public bool $required = false,
        public ?array $object_fields = null,
        public ?array $options = null,
        public ?string $label = null,
    ) {}
}
