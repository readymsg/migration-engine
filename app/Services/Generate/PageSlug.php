<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\InventoryPage;
use Illuminate\Support\Str;

// Single source of truth for the slug a Keep content page is sent to the
// LLM with — same slug used by IrPass for diff/retry and by AnthropicIrPassAgent
// when constructing the prompt. Drift between the two would silently lose
// pages on the diff, so don't inline this logic anywhere else.
final class PageSlug
{
    public static function of(InventoryPage $page): string
    {
        if ($page->page_node_id !== null) {
            return 'page-'.$page->page_node_id;
        }

        return Str::slug($page->label !== '' ? $page->label : 'page');
    }
}
