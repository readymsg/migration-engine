<?php

declare(strict_types=1);

namespace App\Services\Extract;

// Real SportsEngine rootNav is per-node, not per-site. There is no single
// "/rootnav" endpoint — the JSON API is `/page/nav/<page_node_id>` and the
// full tree is built by BFS, expanding any sibling/child whose
// has_child > 0.
interface RootNavFetcher
{
    /**
     * Fetch one node of the rootNav tree by its numeric page_node_id.
     *
     * @return array<string, mixed>  raw JSON (keys: name, id, url, node_type, has_child, siblings, children, parent, …)
     */
    public function fetchNode(string $orgUrl, int $pageNodeId): array;
}
