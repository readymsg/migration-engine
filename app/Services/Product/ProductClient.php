<?php

declare(strict_types=1);

namespace App\Services\Product;

// One of the two seams to the product. Stubbed today; wired later.
// The engine NEVER touches the product DB directly — everything goes through here.
//
// DRAFT-ONLY GUARANTEE (load-bearing safety): this interface intentionally
// exposes NO method that could publish a site. There is no publishSite(),
// no setSitePublished(), no `published: bool` parameter on createDraftSite().
// The engine literally cannot publish — by construction. When the real
// HTTP client lands, it MUST call a product endpoint that is itself draft-
// only (e.g. POST /api/migrations/drafts that flips publish=false on the
// product side). Do not grow a publish method on this interface; if you
// need to flip publish state, that lives on the product side under
// human-mediated review, not under this engine's reach.
//
// KNOWN GAP: today this contract is stubbed (StubProductClient logs the
// call and returns a fake URL). The above guarantee is enforced by THIS
// engine having nothing to call. The end-to-end "real product endpoint is
// genuinely draft-only" property is NOT tested while the client is a
// stub — when the real HTTP client lands, that verification (probably an
// integration test against a staging product) is a required gate before
// any conversion lands against a real org.
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
     * @param  array<string, mixed>  $provisioning  teams/divisions/admins payload (v1: always empty — site rebuild only)
     * @return array{draft_id: string, draft_url: string}
     */
    public function createDraftSite(string $orgId, array $puck, array $provisioning): array;
}
