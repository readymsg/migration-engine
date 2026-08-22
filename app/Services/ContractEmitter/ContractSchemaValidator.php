<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\SiteImport\Block;
use App\Data\SiteImport\ValidationIssue;

// Site Import Contract Part VI "Self-checking before you ship" — the
// block-level and per-page rules. Envelope-level structural checks
// (single homepage, slug uniqueness, tl-asset:<ref>/assets[]
// reconciliation) live in Slice 9's ContractPayloadEmitter which
// composes this validator with page-tree and asset-ledger checks.
//
// PER-BLOCK RULES (what this class enforces):
//   1. Block `type` exists in the catalogue.
//   2. Block is NOT one of the six chrome/intake blocks. Same
//      severity as (1): emitting a chrome block renders a grey
//      placeholder on the live site (Contract Part I rule 1).
//   3. Every prop key exists in that block's `props.properties`
//      OR is one of the block's stored-but-no-editor-field props
//      (represented in `defaults`). Contract Part VI self-check
//      rule 2 pinned this dual source of truth explicitly.
//   4. No prop name starts with `resolved`, and no `formUuid`.
//      Contract Part II "Do not author these" — server-owned.
//   5. Every enum value is in the allowed list, with STRICT-TYPE
//      comparison (`"2"` string ≠ `2` number). Contract Part III
//      "Reading the type column" is explicit about this.
//   6. Every number is a JSON number (not string) and inside the
//      declared range where one exists. Ranges are editor slider
//      bounds not saved-value validation per Part III, so range
//      violations are WARNINGS not errors.
//   7. Every block has `props.id`. Uniqueness within page is
//      enforced by the page-level validator (Slice 6).
//
// Every issue is a ValidationIssue with a dotted path that echoes
// our old BlockValidator's convention (e.g. `props.imageUrl`,
// `props.images[3].src`).
final class ContractSchemaValidator
{
    public function __construct(
        private readonly ContractSchema $schema,
    ) {}

    /**
     * @return array<int, ValidationIssue>
     */
    public function validateBlock(Block $block, string $pathPrefix = ''): array
    {
        $issues = [];
        $prefix = $pathPrefix === '' ? '' : $pathPrefix.'.';

        // Rule 1 + 2: known type, not chrome-only.
        if (! $this->schema->hasBlock($block->type)) {
            $issues[] = new ValidationIssue(
                severity: 'error',
                code: 'unknown_block_type',
                message: "Unknown block type `{$block->type}` — not in the contract catalogue. An unknown type renders a grey 'No configuration for X' placeholder on the live site.",
                path: $pathPrefix,
            );

            return $issues; // nothing else applies without a known schema
        }
        if ($this->schema->isChromeBlock($block->type)) {
            $entry = $this->schema->block($block->type) ?? [];
            $reason = is_string($entry['chromeReason'] ?? null) ? $entry['chromeReason'] : 'Never emitted by import.';
            $issues[] = new ValidationIssue(
                severity: 'error',
                code: 'chrome_block_emitted',
                message: "Block `{$block->type}` is one of the six blocks the contract forbids importing. {$reason}",
                path: $pathPrefix,
            );

            // Fall through — still validate remaining props so tests see the full picture,
            // but the block-level error alone is enough to abort emission.
        }

        // Rule 7: props.id required.
        $id = $block->props['id'] ?? null;
        if (! is_string($id) || $id === '') {
            $issues[] = new ValidationIssue(
                severity: 'error',
                code: 'missing_block_id',
                message: "Block `{$block->type}` is missing `props.id`. Every block needs a unique id within its page (Puck's React key).",
                path: "{$prefix}props.id",
            );
        }

        // Rule 3 + 4: prop-key legality. Slice 15 reads the exact
        // serverOwnedProps and storedOnlyProps lists from the file
        // (x-teamlinkt.vocabularies) instead of a `resolved*` prefix
        // heuristic that could false-positive on org content.
        $properties = $this->schema->propProperties($block->type);
        $defaults = $this->schema->defaults($block->type);
        $doNotAuthor = $this->schema->doNotAuthorProps($block->type);
        $serverOwnedForBlock = $this->serverOwnedTopKeysFor($block->type);
        $storedOnlyForBlock = $this->storedOnlyTopKeysFor($block->type);

        foreach ($block->props as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            // id is universal — checked above, allowed on every block.
            if ($key === 'id') {
                continue;
            }
            // Rule 4a: exact serverOwnedProps path match (file-driven).
            if (in_array($key, $serverOwnedForBlock, true) || in_array($key, $doNotAuthor, true)) {
                $issues[] = new ValidationIssue(
                    severity: 'error',
                    code: 'server_owned_prop_authored',
                    message: "Prop `{$key}` on `{$block->type}` is server-owned (x-teamlinkt.vocabularies.serverOwnedProps). Never author it.",
                    path: "{$prefix}props.{$key}",
                );

                continue;
            }
            // Rule 4b: belt-and-braces heuristic for anything that
            // slipped through — the file might grow a new server-
            // owned prop before we regenerate the fixture; the
            // `resolved` prefix + `formUuid` sentinel remain a
            // structural guarantee.
            if (str_starts_with($key, 'resolved') || $key === 'formUuid') {
                $issues[] = new ValidationIssue(
                    severity: 'error',
                    code: 'server_owned_prop_authored',
                    message: "Prop `{$key}` on `{$block->type}` matches the server-owned naming convention (`resolved*` / `formUuid`).",
                    path: "{$prefix}props.{$key}",
                );

                continue;
            }
            // Rule 3: must exist in properties OR be a listed stored-only
            // prop (the "no editor field but you may set it" escape hatch
            // — e.g. Hero.visibility). storedOnlyProps is the exact list
            // from x-teamlinkt.vocabularies.storedOnlyProps.paths.
            $inProperties = array_key_exists($key, $properties);
            $isStoredOnly = in_array($key, $storedOnlyForBlock, true) || array_key_exists($key, $defaults);
            if (! $inProperties && ! $isStoredOnly) {
                $issues[] = new ValidationIssue(
                    severity: 'error',
                    code: 'unknown_prop_key',
                    message: "Prop `{$key}` is not declared on `{$block->type}`. A typo'd prop is silently stored forever and the block falls back to its default (Contract Part VI 'Block props are a storage contract').",
                    path: "{$prefix}props.{$key}",
                );

                continue;
            }

            // Rule 5 + 6: type/enum/range checks (only when we have a schema entry for this prop).
            if ($inProperties) {
                $propSchema = $properties[$key];
                foreach ($this->validateValue($propSchema, $value, "{$prefix}props.{$key}") as $issue) {
                    $issues[] = $issue;
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $propSchema
     * @return array<int, ValidationIssue>
     */
    private function validateValue(array $propSchema, mixed $value, string $path): array
    {
        $issues = [];
        $type = $propSchema['type'] ?? null;

        switch ($type) {
            case 'enum':
                $enum = $propSchema['enum'] ?? [];
                if (! is_array($enum)) {
                    break;
                }
                if (! in_array($value, $enum, true)) {
                    // Show allowed values with their JSON-typed presentation so a
                    // reviewer can see whether the mismatch was a string-vs-number bug.
                    $shown = implode(', ', array_map(
                        static fn ($v) => is_string($v) ? "`\"{$v}\"`" : '`'.json_encode($v).'`',
                        $enum,
                    ));
                    $issues[] = new ValidationIssue(
                        severity: 'error',
                        code: 'enum_value_invalid',
                        message: 'Value '.self::describe($value)." is not in the allowed enum: {$shown}.",
                        path: $path,
                    );
                }
                break;

            case 'number':
                if (! is_int($value) && ! is_float($value)) {
                    $issues[] = new ValidationIssue(
                        severity: 'error',
                        code: 'wrong_type',
                        message: 'Expected number, got '.self::describe($value).'.',
                        path: $path,
                    );
                    break;
                }
                $min = $propSchema['minimum'] ?? null;
                $max = $propSchema['maximum'] ?? null;
                if (is_numeric($min) && $value < $min) {
                    // Range violations are WARNINGS per Contract Part III
                    // ("Ranges are editor slider bounds, not validation").
                    $issues[] = new ValidationIssue(
                        severity: 'warning',
                        code: 'number_below_slider_range',
                        message: "Value {$value} is below the editor's slider minimum ({$min}). Not saved-value validation, but stay inside.",
                        path: $path,
                    );
                }
                if (is_numeric($max) && $value > $max) {
                    $issues[] = new ValidationIssue(
                        severity: 'warning',
                        code: 'number_above_slider_range',
                        message: "Value {$value} is above the editor's slider maximum ({$max}). Not saved-value validation, but stay inside.",
                        path: $path,
                    );
                }
                break;

            case 'string':
                if (! is_string($value)) {
                    $issues[] = new ValidationIssue(
                        severity: 'error',
                        code: 'wrong_type',
                        message: 'Expected string, got '.self::describe($value).'. (Note: sending null is never correct — omit the key.)',
                        path: $path,
                    );
                }
                break;

            case 'richtext':
                if (! is_string($value)) {
                    $issues[] = new ValidationIssue(
                        severity: 'error',
                        code: 'wrong_type',
                        message: 'Expected richtext string, got '.self::describe($value).'.',
                        path: $path,
                    );
                }
                // HTML-vocabulary sanitising is Slice 4's job — this
                // validator only checks the value is a string. If we
                // did TipTap-subset checking here it would live-couple
                // to the sanitiser and duplicate its logic.
                break;

            case 'object':
                if (! is_array($value)) {
                    $issues[] = new ValidationIssue(
                        severity: 'error',
                        code: 'wrong_type',
                        message: 'Expected object, got '.self::describe($value).'.',
                        path: $path,
                    );
                    break;
                }
                $keys = $propSchema['keys'] ?? [];
                if (is_array($keys)) {
                    foreach ($value as $k => $v) {
                        if (! is_string($k)) {
                            continue;
                        }
                        if (! isset($keys[$k])) {
                            $issues[] = new ValidationIssue(
                                severity: 'error',
                                code: 'unknown_object_key',
                                message: "Unknown key `{$k}` on object at {$path}.",
                                path: "{$path}.{$k}",
                            );

                            continue;
                        }
                        $subSchema = $keys[$k];
                        if (is_array($subSchema)) {
                            foreach ($this->validateValue($subSchema, $v, "{$path}.{$k}") as $sub) {
                                $issues[] = $sub;
                            }
                        }
                    }
                }
                break;

            case 'array':
                if (! is_array($value)) {
                    $issues[] = new ValidationIssue(
                        severity: 'error',
                        code: 'wrong_type',
                        message: 'Expected array, got '.self::describe($value).'.',
                        path: $path,
                    );
                    break;
                }
                $items = $propSchema['items'] ?? null;
                if (is_array($items)) {
                    foreach ($value as $i => $entry) {
                        foreach ($this->validateValue($items, $entry, "{$path}[{$i}]") as $sub) {
                            $issues[] = $sub;
                        }
                    }
                }
                break;

            case 'boolean':
                if (! is_bool($value)) {
                    $issues[] = new ValidationIssue(
                        severity: 'error',
                        code: 'wrong_type',
                        message: 'Expected boolean, got '.self::describe($value).'.',
                        path: $path,
                    );
                }
                break;

            case 'string_or_number':
                // Slice 16b knownDiscrepancies: schema declares string
                // but the block ships numbers. Accept either.
                if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                    $issues[] = new ValidationIssue(
                        severity: 'error',
                        code: 'wrong_type',
                        message: 'Expected string or number, got '.self::describe($value).'.',
                        path: $path,
                    );
                }
                break;

            case 'slot':
                // Slot: array of block objects. Block-shape validation
                // recurses via ContractPayloadEmitter's page-tree walk,
                // not here — this validator sees ONE block at a time.
                if (! is_array($value)) {
                    $issues[] = new ValidationIssue(
                        severity: 'error',
                        code: 'wrong_type',
                        message: 'Slot expects an array of blocks, got '.self::describe($value).'.',
                        path: $path,
                    );
                }
                break;

            case 'opaque':
                // Opaque server-owned reference (TeamRoster.selection.divisionIds[] etc.).
                // Any JSON value type is acceptable — reviewer / product side owns the shape.
                break;
        }

        // Handle nullable strings (Locations.items[].description) — null
        // is a legitimate value here per the knownDiscrepancy.
        if ($type === 'string' && ($propSchema['nullable'] ?? false) === true && $value === null) {
            // silently allowed; would otherwise hit the string wrong_type branch above.
            $issues = array_values(array_filter($issues, static function (ValidationIssue $i) use ($path): bool {
                return ! ($i->code === 'wrong_type' && $i->path === $path);
            }));
        }

        return $issues;
    }

    /**
     * @return array<int, string> top-level prop names on this block that are server-owned
     */
    private function serverOwnedTopKeysFor(string $blockType): array
    {
        return $this->topKeysMatchingBlock($this->schema->serverOwnedProps(), $blockType);
    }

    /**
     * @return array<int, string> top-level prop names on this block that are stored-only (no editor field)
     */
    private function storedOnlyTopKeysFor(string $blockType): array
    {
        return $this->topKeysMatchingBlock($this->schema->storedOnlyProps(), $blockType);
    }

    /**
     * Extract the top-level prop names for `$blockType` from a list of
     * dotted paths (e.g. `Hero.visibility`, `IntakeForm.resolvedQuestions`).
     *
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function topKeysMatchingBlock(array $paths, string $blockType): array
    {
        $prefix = $blockType.'.';
        $out = [];
        foreach ($paths as $path) {
            if (! str_starts_with($path, $prefix)) {
                continue;
            }
            $tail = substr($path, strlen($prefix));
            $head = strtok($tail, '.[');
            if (is_string($head) && ! in_array($head, $out, true)) {
                $out[] = $head;
            }
        }

        return $out;
    }

    private static function describe(mixed $value): string
    {
        if ($value === null) {
            return '`null`';
        }
        if (is_bool($value)) {
            return $value ? '`true`' : '`false`';
        }
        if (is_int($value) || is_float($value)) {
            return "`{$value}`";
        }
        if (is_string($value)) {
            $truncated = strlen($value) > 40 ? substr($value, 0, 40).'…' : $value;

            return "`\"{$truncated}\"` (string)";
        }
        if (is_array($value)) {
            return '`array`';
        }

        return '`'.gettype($value).'`';
    }
}
