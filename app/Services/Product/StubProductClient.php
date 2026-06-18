<?php

declare(strict_types=1);

namespace App\Services\Product;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// In-memory stub. Logs the call shape and returns a fake success response.
// Replaced by the real HTTP client at graduation time.
final class StubProductClient implements ProductClient
{
    public function getComponentSchema(): array
    {
        Log::info('StubProductClient::getComponentSchema called');

        return [];
    }

    public function createDraftSite(string $orgId, array $puck, array $provisioning): array
    {
        $draftId = (string) Str::ulid();

        Log::info('StubProductClient::createDraftSite called', [
            'org_id' => $orgId,
            'page_count' => count($puck),
            'page_slugs' => array_keys($puck),
            'teams_count' => count($provisioning['teams'] ?? []),
            'divisions_count' => count($provisioning['divisions'] ?? []),
            'admins_count' => count($provisioning['admins'] ?? []),
            'draft_id' => $draftId,
        ]);

        return [
            'draft_id' => $draftId,
            'draft_url' => "https://teamlinkt.test/drafts/{$draftId}",
        ];
    }
}
