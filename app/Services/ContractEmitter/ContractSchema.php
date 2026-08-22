<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use RuntimeException;

// Loader for the Site Import Contract's block catalogue. Reads
// resources/site-import-schema/blocks.json — hand-encoded today
// against contract v1 Part III; when engineering delivers
// core/storage/app/ai-website-builder-schema.json this loader is
// the single seam that swaps to that file (see
// resources/site-import-schema/PROVENANCE.md).
//
// The whole point of routing through this class is:
//   1. Validator queries by block type rather than casing over the
//      raw JSON — so the file's exact key names can shift and only
//      this loader breaks.
//   2. All I/O is here — the validator is pure functions over the
//      returned schema, unit-testable without hitting disk.
final class ContractSchema
{
    /**
     * @param  array<string, mixed>  $data  parsed JSON; block-name-keyed under `blocks`
     */
    private function __construct(private readonly array $data) {}

    public static function load(?string $path = null): self
    {
        $resolvedPath = $path ?? resource_path('site-import-schema/blocks.json');
        if (! is_file($resolvedPath)) {
            throw new RuntimeException("Contract schema catalogue not found: {$resolvedPath}");
        }
        $raw = file_get_contents($resolvedPath);
        if ($raw === false) {
            throw new RuntimeException("Contract schema catalogue unreadable: {$resolvedPath}");
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! isset($decoded['blocks']) || ! is_array($decoded['blocks'])) {
            throw new RuntimeException("Contract schema catalogue missing top-level `blocks` map: {$resolvedPath}");
        }

        return new self($decoded);
    }

    public function schemaVersion(): int
    {
        $v = $this->data['schemaVersion'] ?? null;

        return is_int($v) ? $v : 0;
    }

    public function hasBlock(string $type): bool
    {
        return isset($this->data['blocks'][$type]) && is_array($this->data['blocks'][$type]);
    }

    /**
     * @return array<string, mixed>|null block schema entry, or null if unknown
     */
    public function block(string $type): ?array
    {
        $entry = $this->data['blocks'][$type] ?? null;

        return is_array($entry) ? $entry : null;
    }

    public function isChromeBlock(string $type): bool
    {
        $entry = $this->block($type);

        return is_array($entry) && ($entry['chromeOnly'] ?? false) === true;
    }

    /**
     * Contract Part II "Org types" gates block legality by org type.
     * Emitting a restricted block for the wrong orgType is an error
     * (not a warning) — Slice 15f enforces this at emit time.
     *
     * @return array<int, string> either ["all"] or a specific subset like ["league","high_school","association"]
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
     * @return array<int, string> list of do-not-author prop names for this block (server-owned)
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
     * @return array<string, array<string, mixed>> prop-name → prop-schema for the top-level props of the block
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
     * @return array<string, mixed> full defaults blob — includes stored-but-not-editable props (e.g. Hero.visibility)
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
     * @return array<int, string> known block types
     */
    public function knownTypes(): array
    {
        $blocks = $this->data['blocks'] ?? [];
        if (! is_array($blocks)) {
            return [];
        }

        return array_keys($blocks);
    }
}
