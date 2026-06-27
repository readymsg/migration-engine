<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\AssemblyCoercion;
use App\Data\FieldDefinition;
use App\Services\Schema\ComponentSchema;

// One repair pass per block. Splits coercions into two classes:
//
//   - NORMALIZATIONS (value-preserving, silent — no issue emitted):
//     stringy-number → number, h1-h6 case fix, whitespace trim,
//     drop-unknown-prop-key, drop-missing-optional-field.
//
//   - SUBSTITUTIONS (value-changing, recorded as Substitution issue):
//     empty href on required ButtonGroup.buttons[].href → '#',
//     select value not in options → documented default (first option).
//
//   - DROPS (block-losing, recorded as Drop issue): unknown
//     component_type, required content field missing/empty on a
//     content block (Hero/Heading/Text/Card title/Image src/alt),
//     wrong type that cannot be losslessly coerced.
//
// The validator's job was to FIND issues; the coercer's job is to
// DECIDE per kind+field what to do, applying coercions in place. After
// the one pass, the assembler re-validates the coerced props — if any
// fatal issue survives, the block is dropped.
//
// Recursion: Columns.columns[].children are themselves blocks. The
// coercer recurses into each child, applying the full pass. A dropped
// child is removed from the children array and emits an issue with
// the child's path. The parent Columns survives even with empty
// children — empty columns/children render as empty space, not as a
// validation failure.
final class BlockCoercer
{
    public function __construct(
        private readonly ComponentSchema $schema,
    ) {}

    /**
     * @param  array<string, mixed>  $props
     */
    public function coerce(string $componentType, array $props): CoercionResult
    {
        $def = $this->schema->get($componentType);
        if ($def === null) {
            $issue = new CoercerIssue(
                component_type: $componentType,
                coercion: AssemblyCoercion::Drop,
                reason: "unknown component_type '{$componentType}' — dropped (not in ComponentSchema)",
                path: null,
            );

            return new CoercionResult(
                coerced_props: null,
                issues: [$issue],
                dropped: true,
                drop_reason: $issue->reason,
            );
        }

        $issues = [];
        $coerced = [];
        $knownKeys = array_keys($def->fields);

        // Silent: drop unknown prop keys.
        foreach ($def->fields as $name => $field) {
            $hasValue = array_key_exists($name, $props);
            $value = $hasValue ? $props[$name] : null;

            $result = $this->coerceField($name, $componentType, $value, $field, "props.{$name}");

            // Drop-field signal: required content field unrecoverable
            // → the WHOLE block is dropped.
            if ($result['drop_block'] === true) {
                $reason = $result['drop_reason'];
                $issue = new CoercerIssue(
                    component_type: $componentType,
                    coercion: AssemblyCoercion::Drop,
                    reason: $reason,
                    path: "props.{$name}",
                );

                return new CoercionResult(
                    coerced_props: null,
                    issues: array_merge($issues, [$issue]),
                    dropped: true,
                    drop_reason: $reason,
                );
            }

            // Skipped (missing optional field) — silent normalization.
            if ($result['skip'] === true) {
                continue;
            }

            $coerced[$name] = $result['value'];
            foreach ($result['issues'] as $sub) {
                $issues[] = $sub;
            }
        }

        // Sanity: drop any non-known keys (silent normalization). Done
        // implicitly by only writing $coerced from $knownKeys above.
        unset($knownKeys);

        return new CoercionResult(
            coerced_props: $coerced,
            issues: $issues,
            dropped: false,
        );
    }

    /**
     * @return array{value: mixed, skip: bool, drop_block: bool, drop_reason: string, issues: array<int, CoercerIssue>}
     */
    private function coerceField(
        string $name,
        string $ownerType,
        mixed $value,
        FieldDefinition $field,
        string $path,
    ): array {
        $missing = $this->isMissing($value);

        if ($missing && ! $field->required) {
            // Silent normalization — drop missing optional field.
            return ['value' => null, 'skip' => true, 'drop_block' => false, 'drop_reason' => '', 'issues' => []];
        }

        if ($missing && $field->required) {
            return $this->coerceMissingRequired($name, $ownerType, $field, $path);
        }

        // Non-missing — type-specific coercion.
        return match ($field->type) {
            'text', 'textarea' => $this->coerceText($value, $name, $ownerType, $path, $field),
            'image' => $this->coerceImage($value, $name, $ownerType, $path),
            'number' => $this->coerceNumber($value, $name, $ownerType, $path),
            'select', 'radio' => $this->coerceEnum($value, $field->options ?? [], $name, $ownerType, $path),
            'object' => $this->coerceObject($value, $field->object_fields ?? [], $name, $ownerType, $path),
            'array' => $this->coerceArray($value, $field, $name, $ownerType, $path),
            default => ['value' => $value, 'skip' => false, 'drop_block' => false, 'drop_reason' => '', 'issues' => []],
        };
    }

    /**
     * @return array{value: mixed, skip: bool, drop_block: bool, drop_reason: string, issues: array<int, CoercerIssue>}
     */
    private function coerceMissingRequired(string $name, string $ownerType, FieldDefinition $field, string $path): array
    {
        // Substitutable cases (Substitution): hrefs default to '#';
        // labels on buttons need a fallback to keep the parent group
        // alive but the empty label is still a content loss → record.
        if ($ownerType === 'ButtonGroup' && $name === 'href') {
            return [
                'value' => '#',
                'skip' => false,
                'drop_block' => false,
                'drop_reason' => '',
                'issues' => [new CoercerIssue(
                    component_type: $ownerType,
                    coercion: AssemblyCoercion::Substitution,
                    reason: "empty required href on button → '#' placeholder",
                    path: $path,
                )],
            ];
        }

        // Note: missing button LABEL is NOT substituted — inventing a
        // label masquerades as real content (the user's "do not
        // downgrade to placeholder text" rule). Drop the button item;
        // if the group has no surviving buttons, the array becomes
        // empty and the parent ButtonGroup is dropped by the array's
        // "required + empty after coercion" rule.

        // Heading.level missing → 'h2' (sensible structural default,
        // content-preserving; the heading TEXT is the load-bearing
        // part and 'h2' is a rendering choice, not invented content).
        if ($ownerType === 'Heading' && $name === 'level') {
            return [
                'value' => 'h2',
                'skip' => false,
                'drop_block' => false,
                'drop_reason' => '',
                'issues' => [new CoercerIssue(
                    component_type: $ownerType,
                    coercion: AssemblyCoercion::Substitution,
                    reason: "missing required level → 'h2'",
                    path: $path,
                )],
            ];
        }

        // Drop-the-block cases: required CONTENT fields where there's
        // no safe substitution. Putting a placeholder here would
        // misrepresent the source (a Heading with no real text, a Card
        // with no real title, an Image with no real src) — same
        // posture as block-fill: visible failure beats a fake rebuild.
        $reason = "required '{$name}' on {$ownerType} is missing — no safe substitution, block dropped";

        return ['value' => null, 'skip' => false, 'drop_block' => true, 'drop_reason' => $reason, 'issues' => []];
    }

    /**
     * @return array{value: mixed, skip: bool, drop_block: bool, drop_reason: string, issues: array<int, CoercerIssue>}
     */
    private function coerceText(mixed $value, string $name, string $ownerType, string $path, FieldDefinition $field): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            // Silent — whitespace trim doesn't alter content semantics.
            return ['value' => $trimmed, 'skip' => false, 'drop_block' => false, 'drop_reason' => '', 'issues' => []];
        }
        if (is_int($value) || is_float($value)) {
            // Scalar number into text — silent cast.
            return ['value' => (string) $value, 'skip' => false, 'drop_block' => false, 'drop_reason' => '', 'issues' => []];
        }
        if (is_bool($value)) {
            return ['value' => $value ? 'true' : 'false', 'skip' => false, 'drop_block' => false, 'drop_reason' => '', 'issues' => []];
        }

        // Array/object can't be sensibly cast.
        if ($field->required) {
            $reason = "required '{$name}' on {$ownerType} is ".get_debug_type($value).' — not coercible to text, block dropped';

            return ['value' => null, 'skip' => false, 'drop_block' => true, 'drop_reason' => $reason, 'issues' => []];
        }

        return ['value' => null, 'skip' => true, 'drop_block' => false, 'drop_reason' => '', 'issues' => []];
    }

    /**
     * @return array{value: mixed, skip: bool, drop_block: bool, drop_reason: string, issues: array<int, CoercerIssue>}
     */
    private function coerceImage(mixed $value, string $name, string $ownerType, string $path): array
    {
        if (is_string($value) && trim($value) !== '') {
            return ['value' => trim($value), 'skip' => false, 'drop_block' => false, 'drop_reason' => '', 'issues' => []];
        }

        $reason = "required image '{$name}' on {$ownerType} is ".get_debug_type($value).' — no safe substitution, block dropped';

        return ['value' => null, 'skip' => false, 'drop_block' => true, 'drop_reason' => $reason, 'issues' => []];
    }

    /**
     * @return array{value: mixed, skip: bool, drop_block: bool, drop_reason: string, issues: array<int, CoercerIssue>}
     */
    private function coerceNumber(mixed $value, string $name, string $ownerType, string $path): array
    {
        if (is_int($value) || is_float($value)) {
            return ['value' => $value, 'skip' => false, 'drop_block' => false, 'drop_reason' => '', 'issues' => []];
        }
        if (is_string($value) && is_numeric(trim($value))) {
            // Silent — '2' → 2 is value-preserving.
            $trimmed = trim($value);
            $cast = str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed;

            return ['value' => $cast, 'skip' => false, 'drop_block' => false, 'drop_reason' => '', 'issues' => []];
        }

        $reason = "required number '{$name}' on {$ownerType} is ".get_debug_type($value).' — not coercible, block dropped';

        return ['value' => null, 'skip' => false, 'drop_block' => true, 'drop_reason' => $reason, 'issues' => []];
    }

    /**
     * @param  array<int, string>  $options
     * @return array{value: mixed, skip: bool, drop_block: bool, drop_reason: string, issues: array<int, CoercerIssue>}
     */
    private function coerceEnum(mixed $value, array $options, string $name, string $ownerType, string $path): array
    {
        // Silent normalisation: whitespace trim + case fix for h1..h6.
        if (is_string($value)) {
            $trimmed = trim($value);
            // Case-fix attempt: lowercase if a lowercase variant is in
            // options (h1-h6 case). Value-preserving so silent.
            if ($trimmed !== $value && in_array($trimmed, $options, true)) {
                return ['value' => $trimmed, 'skip' => false, 'drop_block' => false, 'drop_reason' => '', 'issues' => []];
            }
            $lower = strtolower($trimmed);
            if ($lower !== $trimmed && in_array($lower, $options, true)) {
                return ['value' => $lower, 'skip' => false, 'drop_block' => false, 'drop_reason' => '', 'issues' => []];
            }
            if (in_array($trimmed, $options, true)) {
                return ['value' => $trimmed, 'skip' => false, 'drop_block' => false, 'drop_reason' => '', 'issues' => []];
            }
            // Out-of-options string → substitute documented default.
            if ($options === []) {
                return ['value' => $trimmed, 'skip' => false, 'drop_block' => false, 'drop_reason' => '', 'issues' => []];
            }
            $default = $options[0];

            return [
                'value' => $default,
                'skip' => false,
                'drop_block' => false,
                'drop_reason' => '',
                'issues' => [new CoercerIssue(
                    component_type: $ownerType,
                    coercion: AssemblyCoercion::Substitution,
                    reason: "value '{$value}' for '{$name}' not in [".implode('|', $options)."] → '{$default}' (documented default)",
                    path: $path,
                )],
            ];
        }

        if ($options === []) {
            return ['value' => $value, 'skip' => false, 'drop_block' => false, 'drop_reason' => '', 'issues' => []];
        }

        $default = $options[0];

        return [
            'value' => $default,
            'skip' => false,
            'drop_block' => false,
            'drop_reason' => '',
            'issues' => [new CoercerIssue(
                component_type: $ownerType,
                coercion: AssemblyCoercion::Substitution,
                reason: 'wrong-type value ('.get_debug_type($value).") for '{$name}' → '{$default}' (documented default)",
                path: $path,
            )],
        ];
    }

    /**
     * @param  array<string, FieldDefinition>  $objectFields
     * @return array{value: mixed, skip: bool, drop_block: bool, drop_reason: string, issues: array<int, CoercerIssue>}
     */
    private function coerceObject(mixed $value, array $objectFields, string $name, string $ownerType, string $path): array
    {
        if (! is_array($value)) {
            // Whole object is wrong type. If it's required, dropping
            // the field is not enough — there's no Puck-rendering of
            // an object string. Drop the block.
            $reason = "object '{$name}' on {$ownerType} is ".get_debug_type($value).' — block dropped';

            return ['value' => null, 'skip' => false, 'drop_block' => true, 'drop_reason' => $reason, 'issues' => []];
        }

        $coerced = [];
        $issues = [];
        foreach ($objectFields as $subName => $subField) {
            $sub = $value[$subName] ?? null;
            $subPath = $this->join($path, $subName);
            $r = $this->coerceField($subName, $ownerType, $sub, $subField, $subPath);
            if ($r['drop_block'] === true) {
                // A drop inside a nested object propagates to the parent.
                return ['value' => null, 'skip' => false, 'drop_block' => true, 'drop_reason' => $r['drop_reason'], 'issues' => array_merge($issues, $r['issues'])];
            }
            if ($r['skip'] !== true) {
                $coerced[$subName] = $r['value'];
            }
            foreach ($r['issues'] as $i) {
                $issues[] = $i;
            }
        }

        return ['value' => $coerced, 'skip' => false, 'drop_block' => false, 'drop_reason' => '', 'issues' => $issues];
    }

    /**
     * @return array{value: mixed, skip: bool, drop_block: bool, drop_reason: string, issues: array<int, CoercerIssue>}
     */
    private function coerceArray(mixed $value, FieldDefinition $field, string $name, string $ownerType, string $path): array
    {
        if (! is_array($value)) {
            $reason = "array '{$name}' on {$ownerType} is ".get_debug_type($value).' — block dropped';

            return ['value' => null, 'skip' => false, 'drop_block' => true, 'drop_reason' => $reason, 'issues' => []];
        }

        // Special case: Columns.columns[].children are nested blocks.
        // Recurse with the full ComponentSchema.
        if ($ownerType === 'Columns' && $name === 'children') {
            return $this->coerceNestedChildren($value, $path);
        }

        $itemFields = $field->object_fields;
        if ($itemFields === null) {
            // Opaque array — pass through.
            return ['value' => array_values($value), 'skip' => false, 'drop_block' => false, 'drop_reason' => '', 'issues' => []];
        }

        $survivors = [];
        $issues = [];
        $i = 0;
        foreach (array_values($value) as $idx => $item) {
            $itemPath = "{$path}[{$idx}]";
            if (! is_array($item)) {
                $issues[] = new CoercerIssue(
                    component_type: $ownerType,
                    coercion: AssemblyCoercion::Drop,
                    reason: "array item at index {$idx} of '{$name}' is ".get_debug_type($item).' — dropped',
                    path: $itemPath,
                );

                continue;
            }
            $coercedItem = [];
            $itemDropped = false;
            $itemDropReason = '';
            $itemIssues = [];
            foreach ($itemFields as $subName => $subField) {
                $sub = $item[$subName] ?? null;
                $r = $this->coerceField($subName, $ownerType, $sub, $subField, $this->join($itemPath, $subName));
                if ($r['drop_block'] === true) {
                    $itemDropped = true;
                    $itemDropReason = $r['drop_reason'];
                    foreach ($r['issues'] as $ri) {
                        $itemIssues[] = $ri;
                    }
                    break;
                }
                if ($r['skip'] !== true) {
                    $coercedItem[$subName] = $r['value'];
                }
                foreach ($r['issues'] as $ri) {
                    $itemIssues[] = $ri;
                }
            }
            if ($itemDropped) {
                $issues[] = new CoercerIssue(
                    component_type: $ownerType,
                    coercion: AssemblyCoercion::Drop,
                    reason: "array item dropped at '{$itemPath}': {$itemDropReason}",
                    path: $itemPath,
                );
                foreach ($itemIssues as $ii) {
                    $issues[] = $ii;
                }

                continue;
            }
            foreach ($itemIssues as $ii) {
                $issues[] = $ii;
            }
            $survivors[] = $coercedItem;
            $i++;
        }

        // If this array was REQUIRED and is now empty after coercion,
        // the parent block has no meaningful content — drop the block.
        if ($field->required && $survivors === []) {
            $reason = "required array '{$name}' on {$ownerType} is empty after coercion — block dropped";

            return ['value' => null, 'skip' => false, 'drop_block' => true, 'drop_reason' => $reason, 'issues' => $issues];
        }

        return ['value' => $survivors, 'skip' => false, 'drop_block' => false, 'drop_reason' => '', 'issues' => $issues];
    }

    /**
     * @param  array<mixed, mixed>  $children
     * @return array{value: mixed, skip: bool, drop_block: bool, drop_reason: string, issues: array<int, CoercerIssue>}
     */
    private function coerceNestedChildren(array $children, string $path): array
    {
        $survivors = [];
        $issues = [];
        foreach (array_values($children) as $idx => $child) {
            $childPath = "{$path}[{$idx}]";
            if (! is_array($child)) {
                $issues[] = new CoercerIssue(
                    component_type: 'unknown',
                    coercion: AssemblyCoercion::Drop,
                    reason: 'nested child must be an object, got '.get_debug_type($child),
                    path: $childPath,
                );

                continue;
            }
            $type = $child['component_type'] ?? $child['type'] ?? null;
            $childProps = $child['props'] ?? [];

            if (! is_string($type) || $type === '') {
                $issues[] = new CoercerIssue(
                    component_type: 'unknown',
                    coercion: AssemblyCoercion::Drop,
                    reason: 'nested child missing component_type',
                    path: $childPath,
                );

                continue;
            }
            if (! is_array($childProps)) {
                $issues[] = new CoercerIssue(
                    component_type: $type,
                    coercion: AssemblyCoercion::Drop,
                    reason: 'nested child props is '.get_debug_type($childProps).', not an object',
                    path: $childPath,
                );

                continue;
            }

            /** @var array<string, mixed> $childProps */
            $result = $this->coerce($type, $childProps);
            // Carry forward all child issues with the child path
            // prefixed onto their own paths.
            foreach ($result->issues as $ci) {
                $combinedPath = $ci->path === null ? $childPath : $this->joinChildPath($childPath, $ci->path);
                $issues[] = new CoercerIssue(
                    component_type: $ci->component_type,
                    coercion: $ci->coercion,
                    reason: $ci->reason,
                    path: $combinedPath,
                );
            }
            if ($result->dropped) {
                continue;
            }
            // Emit Puck-shaped child: {type, props}.
            $survivors[] = ['type' => $type, 'props' => $result->coerced_props ?? []];
        }

        return ['value' => $survivors, 'skip' => false, 'drop_block' => false, 'drop_reason' => '', 'issues' => $issues];
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

    private function joinChildPath(string $childPath, string $relative): string
    {
        if ($relative === '') {
            return $childPath;
        }
        if (str_starts_with($relative, '[')) {
            return $childPath.$relative;
        }

        return $childPath.'.'.$relative;
    }
}
