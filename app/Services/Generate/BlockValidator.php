<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\FieldDefinition;
use App\Services\Schema\ComponentSchema;

// Strict structural validator. Pure — no coercion, no body re-check,
// no LLM. Reports every conformance violation it finds against the
// ComponentSchema as a flat list of ValidationIssue, each with a
// dotted path into the offending field.
//
// Recurses into:
//   - object fields (Hero.cta)
//   - arrays of object (ButtonGroup.buttons[])
//   - Columns.columns[].children — special case, treated as nested
//     {component_type, props} sub-blocks and validated against the
//     full ComponentSchema. ('children' is type='array' in the schema
//     with no object_fields constraint — the schema itself doesn't
//     know children are nested Puck blocks; the assembler does.)
//
// Children inside Columns.columns[].children may arrive in either
// {component_type, props} (FilledBlock-shaped, the agent's convention)
// or {type, props} (already Puck-shaped) — both shapes are accepted.
final class BlockValidator
{
    public function __construct(
        private readonly ComponentSchema $schema,
    ) {}

    /**
     * @param  array<string, mixed>  $props
     * @return array<int, ValidationIssue>
     */
    public function validate(string $componentType, array $props, string $path = ''): array
    {
        $def = $this->schema->get($componentType);
        if ($def === null) {
            return [new ValidationIssue(
                path: $path,
                kind: ValidationKind::UnknownComponent,
                detail: "component_type '{$componentType}' is not in ComponentSchema",
            )];
        }

        $issues = [];

        // Unknown prop keys — silent normalization later, but flag for completeness.
        $knownKeys = array_keys($def->fields);
        foreach (array_keys($props) as $key) {
            if (! in_array($key, $knownKeys, true)) {
                $issues[] = new ValidationIssue(
                    path: $this->join($path, "props.{$key}"),
                    kind: ValidationKind::UnknownProp,
                    detail: "prop '{$key}' is not a schema field of {$componentType}",
                );
            }
        }

        // Each schema-defined field.
        foreach ($def->fields as $name => $field) {
            $value = $props[$name] ?? null;
            $fieldPath = $this->join($path, "props.{$name}");
            $issues = array_merge(
                $issues,
                $this->validateField($name, $componentType, $value, $field, $fieldPath),
            );
        }

        return $issues;
    }

    /**
     * @return array<int, ValidationIssue>
     */
    private function validateField(
        string $name,
        string $ownerType,
        mixed $value,
        FieldDefinition $field,
        string $fieldPath,
    ): array {
        if ($this->isMissing($value)) {
            if ($field->required) {
                return [new ValidationIssue(
                    path: $fieldPath,
                    kind: ValidationKind::MissingRequired,
                    detail: "required field '{$name}' on {$ownerType} is missing or empty",
                )];
            }

            return [];
        }

        return match ($field->type) {
            'text', 'textarea', 'image' => $this->validateString($value, $name, $ownerType, $fieldPath, $field->type),
            'number' => $this->validateNumber($value, $name, $ownerType, $fieldPath),
            'select', 'radio' => $this->validateEnum($value, $field->options ?? [], $name, $ownerType, $fieldPath, $field->type),
            'object' => $this->validateObject($value, $field->object_fields ?? [], $name, $ownerType, $fieldPath),
            'array' => $this->validateArray($value, $field, $name, $ownerType, $fieldPath),
            default => [],
        };
    }

    /**
     * @return array<int, ValidationIssue>
     */
    private function validateString(mixed $value, string $name, string $ownerType, string $fieldPath, string $declared): array
    {
        if (! is_string($value)) {
            return [new ValidationIssue(
                path: $fieldPath,
                kind: ValidationKind::WrongType,
                detail: "field '{$name}' on {$ownerType} expects {$declared}, got ".$this->typeName($value),
            )];
        }

        return [];
    }

    /**
     * @return array<int, ValidationIssue>
     */
    private function validateNumber(mixed $value, string $name, string $ownerType, string $fieldPath): array
    {
        if (is_int($value) || is_float($value)) {
            return [];
        }

        // Stringy-numbers are flagged (coercer normalises silently).
        return [new ValidationIssue(
            path: $fieldPath,
            kind: ValidationKind::WrongType,
            detail: "field '{$name}' on {$ownerType} expects number, got ".$this->typeName($value),
        )];
    }

    /**
     * @param  array<int, string>  $options
     * @return array<int, ValidationIssue>
     */
    private function validateEnum(mixed $value, array $options, string $name, string $ownerType, string $fieldPath, string $declared): array
    {
        if (! is_string($value)) {
            return [new ValidationIssue(
                path: $fieldPath,
                kind: ValidationKind::WrongType,
                detail: "field '{$name}' on {$ownerType} expects {$declared} (string), got ".$this->typeName($value),
            )];
        }
        if ($options === []) {
            return [];
        }
        if (in_array($value, $options, true)) {
            return [];
        }

        return [new ValidationIssue(
            path: $fieldPath,
            kind: ValidationKind::InvalidSelectValue,
            detail: "value '{$value}' for '{$name}' on {$ownerType} not in options [".implode('|', $options).']',
        )];
    }

    /**
     * @param  array<string, FieldDefinition>  $objectFields
     * @return array<int, ValidationIssue>
     */
    private function validateObject(mixed $value, array $objectFields, string $name, string $ownerType, string $fieldPath): array
    {
        if (! is_array($value)) {
            return [new ValidationIssue(
                path: $fieldPath,
                kind: ValidationKind::WrongType,
                detail: "field '{$name}' on {$ownerType} expects object, got ".$this->typeName($value),
            )];
        }

        $issues = [];
        foreach ($objectFields as $subName => $subField) {
            $subValue = $value[$subName] ?? null;
            $subPath = $this->join($fieldPath, $subName);
            $issues = array_merge(
                $issues,
                $this->validateField($subName, $ownerType, $subValue, $subField, $subPath),
            );
        }

        return $issues;
    }

    /**
     * @return array<int, ValidationIssue>
     */
    private function validateArray(mixed $value, FieldDefinition $field, string $name, string $ownerType, string $fieldPath): array
    {
        if (! is_array($value)) {
            return [new ValidationIssue(
                path: $fieldPath,
                kind: ValidationKind::WrongType,
                detail: "field '{$name}' on {$ownerType} expects array, got ".$this->typeName($value),
            )];
        }

        // Columns.columns[].children — special case: items are nested
        // Puck-shaped sub-blocks. The schema declares 'children' as a
        // bare 'array' (no object_fields), so we'd otherwise treat it
        // as opaque. Recurse with the full ComponentSchema instead.
        if ($ownerType === 'Columns' && $name === 'children') {
            return $this->validateNestedChildren($value, $fieldPath);
        }

        $itemFields = $field->object_fields;
        if ($itemFields === null) {
            // Bare 'array' — no per-item schema, treat as opaque
            // (Puck will pass through whatever's here).
            return [];
        }

        $issues = [];
        foreach (array_values($value) as $i => $item) {
            $itemPath = "{$fieldPath}[{$i}]";
            if (! is_array($item)) {
                $issues[] = new ValidationIssue(
                    path: $itemPath,
                    kind: ValidationKind::WrongType,
                    detail: "array item at index {$i} of '{$name}' on {$ownerType} expects object, got ".$this->typeName($item),
                );

                continue;
            }
            // Recurse into Columns.columns[i] items — they themselves
            // contain a 'children' field which IS the Puck-nested case.
            foreach ($itemFields as $subName => $subField) {
                $subValue = $item[$subName] ?? null;
                $issues = array_merge(
                    $issues,
                    $this->validateField($subName, $ownerType, $subValue, $subField, $this->join($itemPath, $subName)),
                );
            }
        }

        return $issues;
    }

    /**
     * @param  array<mixed, mixed>  $children
     * @return array<int, ValidationIssue>
     */
    private function validateNestedChildren(array $children, string $fieldPath): array
    {
        $issues = [];
        foreach (array_values($children) as $i => $child) {
            $childPath = "{$fieldPath}[{$i}]";
            if (! is_array($child)) {
                $issues[] = new ValidationIssue(
                    path: $childPath,
                    kind: ValidationKind::WrongType,
                    detail: 'nested child must be an object, got '.$this->typeName($child),
                );

                continue;
            }
            // Accept both {component_type, props} (FilledBlock-shaped) and
            // {type, props} (Puck-shaped). The agent's convention is the
            // former; the assembler tolerates the latter for resilience.
            $type = $child['component_type'] ?? $child['type'] ?? null;
            $childProps = $child['props'] ?? [];

            if (! is_string($type) || $type === '') {
                $issues[] = new ValidationIssue(
                    path: $childPath,
                    kind: ValidationKind::UnknownComponent,
                    detail: 'nested child missing component_type',
                );

                continue;
            }
            if (! is_array($childProps)) {
                $issues[] = new ValidationIssue(
                    path: $this->join($childPath, 'props'),
                    kind: ValidationKind::WrongType,
                    detail: 'nested child props expects object, got '.$this->typeName($childProps),
                );

                continue;
            }

            /** @var array<string, mixed> $childProps */
            $issues = array_merge($issues, $this->validate($type, $childProps, $childPath));
        }

        return $issues;
    }

    private function isMissing(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value) && trim($value) === '') {
            return true;
        }
        if (is_array($value) && $value === []) {
            return true;
        }

        return false;
    }

    private function typeName(mixed $value): string
    {
        return get_debug_type($value);
    }

    private function join(string $left, string $right): string
    {
        if ($left === '') {
            return $right;
        }
        if (str_starts_with($right, '[')) {
            return $left.$right;
        }

        return $left.'.'.$right;
    }
}
