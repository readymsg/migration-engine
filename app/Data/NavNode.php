<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class NavNode extends Data
{
    /**
     * @param  DataCollection<int, NavNode>  $children
     */
    public function __construct(
        public string $label,
        public ?string $url,
        // Derived classification from `node_type`:
        // 'page' | 'dynamic_calendar' | 'dynamic_news' | 'dynamic_other' | 'unknown'.
        // Down-stream planner can collapse the 'dynamic_*' variants into a single
        // 'dynamic' disposition; the finer types are kept so we don't lose the signal.
        public string $kind,
        #[DataCollectionOf(NavNode::class)]
        public DataCollection $children,
        // Raw SportsEngine node_type as returned by /page/nav/<id>:
        // 'Page' | 'Calendar' | 'NewsNode' | null (root) | other.
        public ?string $node_type = null,
        // Numeric id parsed out of the `page_node_<int>` string. Lets the planner
        // re-fetch a node or correlate with rootNav data.
        public ?int $page_node_id = null,
    ) {}
}
