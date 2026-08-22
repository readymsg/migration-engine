<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use RuntimeException;

// Loader for the TeamLinkt Site Import Contract's block catalogue.
// Slice 14: reads engineering's `site-import-schema.json` (JSON
// Schema draft 2020-12) directly. The 13-block hand-encoded
// `blocks.json` fallback is gone; we now consume the full
// 45-block catalogue.
//
// The class is an ADAPTER: the real schema follows standard
// JSON Schema conventions (`properties`/`items`, top-level `$defs`,
// x-teamlinkt extensions), but the rest of the codebase queries
// it through a small stable interface (hasBlock / propProperties /
// defaults / etc). All shape adaptation happens inside this class
// so callers (validator, org-type gate, contract-audit command)
// don't churn when the file's structure changes.
//
// Internal shape (post-normalization):
//   blocks[<BlockType>] = {
//     description, category, emittable, availableInZones, orgTypes,
//     defaults, chromeOnly, chromeReason,
//     props: { required: ['id'], properties: { <name> => <normalized> } },
//     doNotAuthor: [<prop names server-owned by this block>],
//   }
// A normalized prop is one of:
//   {type: 'string'}                  — plain string
//   {type: 'string', nullable: true}  — string|null union (knownDiscrepancies)
//   {type: 'number', minimum, maximum} — numeric with slider bounds
//   {type: 'boolean'}                 — boolean
//   {type: 'enum', enum: [...]}       — enum (JSON types preserved)
//   {type: 'richtext'}                — richtext prop (from x-teamlinkt.richtext.props)
//   {type: 'opaque'}                  — opaque server-owned reference (opaqueProps)
//   {type: 'slot'}                    — slot: array of blocks
//   {type: 'array', items: <normalized>}
//   {type: 'object', keys: {<name> => <normalized>}}
//   {type: 'string_or_number'}        — for the three knownDiscrepancy props (Statistics.items[].value etc.)
final class ContractSchema
{
    /**
     * @param  array<string, mixed>  $raw  the full parsed site-import-schema.json
     * @param  array<string, array<string, mixed>>  $normalizedBlocks  block name → normalized entry
     */
    private function __construct(
        private readonly array $raw,
        private readonly array $normalizedBlocks,
    ) {}

    public static function load(?string $path = null): self
    {
        $resolvedPath = $path ?? resource_path('site-import-schema/site-import-schema.json');
        if (! is_file($resolvedPath)) {
            throw new RuntimeException("Contract schema not found: {$resolvedPath}");
        }
        $raw = file_get_contents($resolvedPath);
        if ($raw === false) {
            throw new RuntimeException("Contract schema unreadable: {$resolvedPath}");
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! isset($decoded['$defs']) || ! is_array($decoded['$defs'])) {
            throw new RuntimeException("Contract schema missing top-level \$defs: {$resolvedPath}");
        }

        $normalized = self::normalizeBlocks($decoded);

        return new self($decoded, $normalized);
    }

    public function schemaVersion(): int
    {
        $xtl = $this->raw['x-teamlinkt'] ?? [];
        $v = is_array($xtl) ? ($xtl['schemaVersion'] ?? null) : null;

        return is_int($v) ? $v : 0;
    }

    public function hasBlock(string $type): bool
    {
        return isset($this->normalizedBlocks[$type]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function block(string $type): ?array
    {
        return $this->normalizedBlocks[$type] ?? null;
    }

    public function isChromeBlock(string $type): bool
    {
        $entry = $this->block($type);

        return is_array($entry) && ($entry['chromeOnly'] ?? false) === true;
    }

    /**
     * @return array<int, string> either ['all'] or a specific subset like ['league','high_school','association']
     */
    public function orgTypesFor(string $type): array
    {
        $entry = $this->block($type);
        if ($entry === null) {
            return ['all'];
        }
        $orgTypes = $entry['orgTypes'] ?? ['all'];
        if (! is_array($orgTypes) || $orgTypes === []) {
            return ['all'];
        }

        return array_values(array_filter($orgTypes, 'is_string'));
    }

    public function blockAllowsOrgType(string $type, string $orgType): bool
    {
        $allowed = $this->orgTypesFor($type);
        if (in_array('all', $allowed, true)) {
            return true;
        }

        return in_array($orgType, $allowed, true);
    }

    /**
     * @return array<int, string>
     */
    public function doNotAuthorProps(string $type): array
    {
        $entry = $this->block($type);
        if ($entry === null) {
            return [];
        }
        $list = $entry['doNotAuthor'] ?? [];
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_filter($list, 'is_string'));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function propProperties(string $type): array
    {
        $entry = $this->block($type);
        if ($entry === null) {
            return [];
        }
        $props = $entry['props']['properties'] ?? [];
        if (! is_array($props)) {
            return [];
        }

        /** @var array<string, array<string, mixed>> */
        return $props;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(string $type): array
    {
        $entry = $this->block($type);
        if ($entry === null) {
            return [];
        }
        $defaults = $entry['defaults'] ?? [];
        if (! is_array($defaults)) {
            return [];
        }

        return $defaults;
    }

    /**
     * @return array<int, string>
     */
    public function knownTypes(): array
    {
        return array_keys($this->normalizedBlocks);
    }

    // ─── x-teamlinkt vocabularies (Slice 15 will consume these) ─────────

    /**
     * @return array<string, mixed>
     */
    public function xTeamlinkt(): array
    {
        $xtl = $this->raw['x-teamlinkt'] ?? [];

        /** @var array<string, mixed> */
        return is_array($xtl) ? $xtl : [];
    }

    /**
     * Which orgTypes are ALLOWED for each restricted block. Read from
     * x-teamlinkt.orgTypeGating.restrictedBlocks (Slice 15 will replace
     * the hardcoded gate matrix with this).
     *
     * @return array<string, array<int, string>>
     */
    public function orgTypeGating(): array
    {
        $xtl = $this->xTeamlinkt();
        $gate = $xtl['orgTypeGating'] ?? [];
        $restricted = is_array($gate) ? ($gate['restrictedBlocks'] ?? []) : [];

        /** @var array<string, array<int, string>> */
        return is_array($restricted) ? $restricted : [];
    }

    /**
     * Blocks that MUST NEVER be emitted (chrome + IntakeForm). Key is
     * block name, value is the reason from x-teamlinkt.neverEmitBlocks.
     *
     * @return array<string, string>
     */
    public function neverEmitBlocks(): array
    {
        $xtl = $this->xTeamlinkt();
        $never = $xtl['neverEmitBlocks'] ?? [];

        /** @var array<string, string> */
        return is_array($never) ? $never : [];
    }

    // ─── plain-string vocabularies (Slice 15 will replace hardcoded uses) ──

    /**
     * @return array<int, string>
     */
    public function reservedTopLevelSlugs(): array
    {
        return $this->stringListVocabulary('reservedTopLevelSlugs');
    }

    /**
     * @return array<int, string>
     */
    public function assetBearingProps(): array
    {
        return $this->stringListVocabulary('assetBearingProps');
    }

    /**
     * @return array<int, string>
     */
    public function opaqueProps(): array
    {
        return $this->stringListVocabulary('opaqueProps');
    }

    /**
     * @return array<int, string>
     */
    public function serverOwnedProps(): array
    {
        return $this->stringListVocabulary('serverOwnedProps');
    }

    /**
     * @return array<int, string>
     */
    public function storedOnlyProps(): array
    {
        $xtl = $this->xTeamlinkt();
        $group = $xtl['vocabularies'][$this::class] ?? null; // never true — kept for shape
        unset($group);
        $vocab = $xtl['vocabularies']['storedOnlyProps'] ?? [];
        $paths = is_array($vocab) ? ($vocab['paths'] ?? []) : [];

        return is_array($paths) ? array_values(array_filter($paths, 'is_string')) : [];
    }

    /**
     * @return array<int, string>
     */
    public function richtextProps(): array
    {
        $xtl = $this->xTeamlinkt();
        $rt = $xtl['vocabularies']['richtext'] ?? [];
        $props = is_array($rt) ? ($rt['props'] ?? []) : [];

        return is_array($props) ? array_values(array_filter($props, 'is_string')) : [];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function slotPaths(): array
    {
        $xtl = $this->xTeamlinkt();
        $slots = $xtl['vocabularies']['slotPaths'] ?? [];

        /** @var array<string, array<int, string>> */
        return is_array($slots) ? $slots : [];
    }

    /**
     * Per-block placeholder URL vocabulary. Keys are block names,
     * values are the placeholder URL list from
     * x-teamlinkt.vocabularies.stockMediaDefaults + per-block
     * stockMediaDefaults blocks.
     *
     * @return array<string, array<int, string>> block name → placeholder URLs
     */
    public function stockMediaDefaults(): array
    {
        $out = [];
        foreach ($this->normalizedBlocks as $type => $entry) {
            $vals = $entry['stockMediaDefaults'] ?? [];
            if (is_array($vals) && $vals !== []) {
                $out[$type] = array_values(array_filter($vals, 'is_string'));
            }
        }

        return $out;
    }

    /**
     * The three knownDiscrepancy paths engineering has already
     * identified. Slice 16b uses these to accept BOTH declared and
     * shipped types on validation.
     *
     * @return array<string, array<int, string>> dotted path → allowed JSON types
     */
    public function knownDiscrepancies(): array
    {
        $xtl = $this->xTeamlinkt();
        $kd = $xtl['knownDiscrepancies'] ?? [];
        $props = is_array($kd) ? ($kd['props'] ?? []) : [];
        if (! is_array($props)) {
            return [];
        }
        $out = [];
        foreach ($props as $path => $entry) {
            if (! is_string($path) || ! is_array($entry)) {
                continue;
            }
            $types = $entry['types'] ?? [];
            if (is_array($types)) {
                $out[$path] = array_values(array_filter($types, 'is_string'));
            }
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function stringListVocabulary(string $name): array
    {
        $xtl = $this->xTeamlinkt();
        $vocab = $xtl['vocabularies'][$name] ?? [];
        if (! is_array($vocab)) {
            return [];
        }

        return array_values(array_filter($vocab, 'is_string'));
    }

    // ─── normalization (real schema → internal shape) ───────────────────

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, array<string, mixed>>
     */
    private static function normalizeBlocks(array $decoded): array
    {
        $defs = $decoded['$defs'] ?? [];
        if (! is_array($defs)) {
            return [];
        }
        $xtl = is_array($decoded['x-teamlinkt'] ?? null) ? $decoded['x-teamlinkt'] : [];
        $neverEmit = is_array($xtl['neverEmitBlocks'] ?? null) ? $xtl['neverEmitBlocks'] : [];
        $richtextProps = self::extractRichtextPropPaths($xtl);
        $opaqueProps = self::extractStringList($xtl, ['vocabularies', 'opaqueProps']);
        $serverOwnedProps = self::extractStringList($xtl, ['serverOwnedProps']);
        $slotPaths = is_array($xtl['vocabularies']['slotPaths'] ?? null)
            ? $xtl['vocabularies']['slotPaths']
            : [];
        $knownDiscrepancies = is_array($xtl['knownDiscrepancies']['props'] ?? null)
            ? $xtl['knownDiscrepancies']['props']
            : [];

        $out = [];
        foreach ($defs as $key => $def) {
            if (! is_string($key) || ! str_starts_with($key, 'block.') || ! is_array($def)) {
                continue;
            }
            $type = substr($key, strlen('block.'));
            $blockXtl = is_array($def['x-teamlinkt'] ?? null) ? $def['x-teamlinkt'] : [];
            $propsSchema = is_array($def['properties']['props'] ?? null)
                ? $def['properties']['props']
                : [];
            $rawProps = is_array($propsSchema['properties'] ?? null)
                ? $propsSchema['properties']
                : [];

            $normalizedProps = [];
            foreach ($rawProps as $name => $propDef) {
                if (! is_string($name) || $name === 'id' || ! is_array($propDef)) {
                    continue;
                }
                $normalizedProps[$name] = self::normalizeProp(
                    propDef: $propDef,
                    dottedPath: $type.'.'.$name,
                    richtextProps: $richtextProps,
                    opaqueProps: $opaqueProps,
                    slotPathsForBlock: is_array($slotPaths[$type] ?? null) ? $slotPaths[$type] : [],
                    knownDiscrepancies: $knownDiscrepancies,
                );
            }

            $emittable = $blockXtl['emittable'] ?? true;
            $chromeOnly = ! $emittable || array_key_exists($type, $neverEmit);
            $chromeReason = is_string($neverEmit[$type] ?? null) ? $neverEmit[$type] : null;

            $doNotAuthor = self::doNotAuthorForBlock($type, $serverOwnedProps);

            $entry = [
                'description' => $def['description'] ?? '',
                'category' => $blockXtl['category'] ?? null,
                'emittable' => $emittable,
                'availableInZones' => $blockXtl['availableInZones'] ?? null,
                'orgTypes' => self::orgTypesForBlock($type, $blockXtl, $xtl),
                'chromeOnly' => $chromeOnly,
                'chromeReason' => $chromeReason,
                'defaults' => is_array($blockXtl['defaults'] ?? null) ? $blockXtl['defaults'] : [],
                'stockMediaDefaults' => self::stockMediaValues($blockXtl),
                'storedOnlyProps' => is_array($blockXtl['storedOnlyProps'] ?? null) ? $blockXtl['storedOnlyProps'] : [],
                'props' => [
                    'required' => is_array($propsSchema['required'] ?? null) ? $propsSchema['required'] : ['id'],
                    'properties' => $normalizedProps,
                ],
                'doNotAuthor' => $doNotAuthor,
            ];
            $out[$type] = $entry;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $propDef
     * @param  array<int, string>  $richtextProps  dotted paths that are richtext
     * @param  array<int, string>  $opaqueProps  dotted paths that are opaque
     * @param  array<int, string>  $slotPathsForBlock  slot prop names for this block
     * @param  array<string, mixed>  $knownDiscrepancies
     * @return array<string, mixed>
     */
    private static function normalizeProp(
        array $propDef,
        string $dottedPath,
        array $richtextProps,
        array $opaqueProps,
        array $slotPathsForBlock,
        array $knownDiscrepancies,
    ): array {
        // Slot: array-of-componentData with x-teamlinkt.slot: true.
        $propXtl = is_array($propDef['x-teamlinkt'] ?? null) ? $propDef['x-teamlinkt'] : [];
        if (($propXtl['slot'] ?? false) === true) {
            return ['type' => 'slot'];
        }

        // Opaque server-owned references (opaqueProps list): TeamRoster.selection.divisionIds[] etc.
        $shortName = substr($dottedPath, strpos($dottedPath, '.') + 1);
        foreach ($opaqueProps as $opaquePath) {
            if (self::pathMatches($opaquePath, $dottedPath)) {
                return ['type' => 'opaque'];
            }
        }

        // Richtext (richtext.props list).
        if (in_array($dottedPath, $richtextProps, true)) {
            return ['type' => 'richtext'];
        }

        // Known discrepancy paths (Statistics.items[].value etc.) → string_or_number if declared allows both.
        if (isset($knownDiscrepancies[$dottedPath])) {
            $entry = $knownDiscrepancies[$dottedPath];
            $types = is_array($entry) && is_array($entry['types'] ?? null) ? $entry['types'] : [];
            if (in_array('string', $types, true) && in_array('number', $types, true)) {
                return ['type' => 'string_or_number'];
            }
            if (in_array('string', $types, true) && in_array('null', $types, true)) {
                return ['type' => 'string', 'nullable' => true];
            }
        }

        // Enum (regardless of declared type — JSON Schema often has `enum` with `type: string` present too).
        if (isset($propDef['enum']) && is_array($propDef['enum'])) {
            return ['type' => 'enum', 'enum' => $propDef['enum']];
        }

        // Type-union like ["string", "null"] — needed for Locations.items[].description.
        $rawType = $propDef['type'] ?? null;
        if (is_array($rawType)) {
            if (in_array('string', $rawType, true) && in_array('null', $rawType, true)) {
                return ['type' => 'string', 'nullable' => true];
            }
            if (in_array('string', $rawType, true) && in_array('number', $rawType, true)) {
                return ['type' => 'string_or_number'];
            }
        }

        switch ($rawType) {
            case 'string':
                return ['type' => 'string'];
            case 'boolean':
                return ['type' => 'boolean'];
            case 'number':
            case 'integer':
                $out = ['type' => 'number'];
                if (isset($propDef['minimum']) && is_numeric($propDef['minimum'])) {
                    $out['minimum'] = $propDef['minimum'];
                }
                if (isset($propDef['maximum']) && is_numeric($propDef['maximum'])) {
                    $out['maximum'] = $propDef['maximum'];
                }

                return $out;
            case 'array':
                $items = $propDef['items'] ?? null;
                if (! is_array($items)) {
                    return ['type' => 'array'];
                }
                $normalizedItems = self::normalizeProp(
                    propDef: $items,
                    dottedPath: $dottedPath.'[]',
                    richtextProps: $richtextProps,
                    opaqueProps: $opaqueProps,
                    slotPathsForBlock: $slotPathsForBlock,
                    knownDiscrepancies: $knownDiscrepancies,
                );

                return ['type' => 'array', 'items' => $normalizedItems];
            case 'object':
                $properties = is_array($propDef['properties'] ?? null) ? $propDef['properties'] : [];
                $keys = [];
                foreach ($properties as $k => $v) {
                    if (! is_string($k) || ! is_array($v)) {
                        continue;
                    }
                    $keys[$k] = self::normalizeProp(
                        propDef: $v,
                        dottedPath: $dottedPath.'.'.$k,
                        richtextProps: $richtextProps,
                        opaqueProps: $opaqueProps,
                        slotPathsForBlock: $slotPathsForBlock,
                        knownDiscrepancies: $knownDiscrepancies,
                    );
                }

                return ['type' => 'object', 'keys' => $keys];
        }

        // Unknown / no declared type — treat as opaque so the validator
        // doesn't false-flag it. The file itself doesn't have this case
        // today but keeping the fallback keeps the loader defensive.
        return ['type' => 'opaque'];
    }

    /**
     * @param  array<string, mixed>  $xtl  top-level x-teamlinkt
     * @return array<int, string> dotted-path list of richtext props (Text.body etc.)
     */
    private static function extractRichtextPropPaths(array $xtl): array
    {
        $rt = $xtl['vocabularies']['richtext']['props'] ?? [];
        if (! is_array($rt)) {
            return [];
        }

        return array_values(array_filter($rt, 'is_string'));
    }

    /**
     * @param  array<string, mixed>  $xtl
     * @param  array<int, string>  $path
     * @return array<int, string>
     */
    private static function extractStringList(array $xtl, array $path): array
    {
        $cur = $xtl;
        foreach ($path as $seg) {
            if (! is_array($cur) || ! isset($cur[$seg])) {
                return [];
            }
            $cur = $cur[$seg];
        }
        if (! is_array($cur)) {
            return [];
        }

        return array_values(array_filter($cur, 'is_string'));
    }

    /**
     * Filter the global serverOwnedProps list down to props of ONE block.
     * Handles both `Block.prop` and `Block.prop.subpath` — we only want
     * the top-level prop name(s) so the validator's per-key check matches.
     *
     * @param  array<int, string>  $globalServerOwned
     * @return array<int, string>
     */
    private static function doNotAuthorForBlock(string $type, array $globalServerOwned): array
    {
        $prefix = $type.'.';
        $out = [];
        foreach ($globalServerOwned as $path) {
            if (! str_starts_with($path, $prefix)) {
                continue;
            }
            $tail = substr($path, strlen($prefix));
            // Take the head segment only — e.g. "IntakeForm.resolvedQuestions" → "resolvedQuestions".
            $head = strtok($tail, '.[');
            if (is_string($head) && ! in_array($head, $out, true)) {
                $out[] = $head;
            }
        }

        return $out;
    }

    /**
     * Merge x-teamlinkt.orgTypeGating.restrictedBlocks with per-block
     * orgTypes so `orgTypesFor(type)` still returns ['all'] or the
     * per-block list.
     *
     * @param  array<string, mixed>  $blockXtl
     * @param  array<string, mixed>  $topXtl
     * @return array<int, string>
     */
    private static function orgTypesForBlock(string $type, array $blockXtl, array $topXtl): array
    {
        $gate = $topXtl['orgTypeGating']['restrictedBlocks'] ?? [];
        if (is_array($gate) && isset($gate[$type]) && is_array($gate[$type])) {
            return array_values(array_filter($gate[$type], 'is_string'));
        }
        $raw = $blockXtl['orgTypes'] ?? 'all';
        if (is_string($raw)) {
            return [$raw];
        }
        if (is_array($raw)) {
            return array_values(array_filter($raw, 'is_string'));
        }

        return ['all'];
    }

    /**
     * @param  array<string, mixed>  $blockXtl
     * @return array<int, string>
     */
    private static function stockMediaValues(array $blockXtl): array
    {
        $stock = $blockXtl['stockMediaDefaults'] ?? [];
        if (! is_array($stock)) {
            return [];
        }
        $values = $stock['values'] ?? [];
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, 'is_string'));
    }

    /**
     * Does `$vocabPath` (e.g. `TeamRoster.selection.divisionIds[]`)
     * match the specific dotted path `$candidate` (e.g.
     * `TeamRoster.selection.divisionIds[]` or `TeamRoster.selection.divisionIds`)?
     */
    private static function pathMatches(string $vocabPath, string $candidate): bool
    {
        if ($vocabPath === $candidate) {
            return true;
        }
        // Strip trailing [] from vocab path for element-level match.
        if (str_ends_with($vocabPath, '[]') && substr($vocabPath, 0, -2) === $candidate) {
            return true;
        }

        return false;
    }
}
