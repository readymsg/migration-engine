<?php

declare(strict_types=1);

namespace App\Services\Product;

// One of the two seams to the product. Stubbed today; wired later.
// The engine NEVER touches the product DB directly — everything goes through here.
interface ProductClient
{
    /**
     * Fetch the product's real component schema export.
     * Today: not used (DefaultPuckComponentSchema is the source of truth).
     *
     * @return array<string, mixed>
     */
    public function getComponentSchema(): array;

    /**
     * Land a conversion as an unpublished draft. Never auto-publish.
     *
     * @param  array<string, array<string, mixed>>  $puck  page_slug => Puck JSON for that page
     * @param  array<string, mixed>  $provisioning  teams/divisions/admins payload
     * @return array{draft_id: string, draft_url: string}
     */
    public function createDraftSite(string $orgId, array $puck, array $provisioning): array;
}
