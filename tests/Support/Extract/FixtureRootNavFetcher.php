<?php

declare(strict_types=1);

namespace Tests\Support\Extract;

use App\Services\Extract\RootNavFetcher;
use RuntimeException;

// Loads /page/nav/<id> JSON from disk. The test asserts that the extractor
// uses the API path; unloaded ids throw so the test fails loud if the
// extractor reaches for one we haven't pinned to a fixture.
final class FixtureRootNavFetcher implements RootNavFetcher
{
    /** @var array<int, array<string, mixed>> */
    private array $nodes = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function preload(int $pageNodeId, array $data): void
    {
        $this->nodes[$pageNodeId] = $data;
    }

    public function preloadFromFile(int $pageNodeId, string $path): void
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Could not read fixture: {$path}");
        }
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException("Fixture {$path} is not a JSON object");
        }
        /** @var array<string, mixed> $decoded */
        $this->preload($pageNodeId, $decoded);
    }

    public function fetchNode(string $orgUrl, int $pageNodeId): array
    {
        if (! array_key_exists($pageNodeId, $this->nodes)) {
            throw new RuntimeException("No fixture for page_node_{$pageNodeId} (orgUrl={$orgUrl})");
        }

        return $this->nodes[$pageNodeId];
    }
}
