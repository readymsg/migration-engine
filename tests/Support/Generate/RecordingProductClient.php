<?php

declare(strict_types=1);

namespace Tests\Support\Generate;

use App\Services\Product\ProductClient;
use Throwable;

// Test double for ProductClient. Records every call so tests can assert
// whether createDraftSite was reached and what payload it received.
// Can be configured to throw a specific exception to exercise the
// client-error branch of DraftLanding.
final class RecordingProductClient implements ProductClient
{
    /** @var array<int, array{org_id: string, puck: array<string, array<string, mixed>>, provisioning: array<string, mixed>}> */
    public array $calls = [];

    private string $nextDraftId = '01TEST_DRAFT_ID';

    private string $nextDraftUrl = 'https://teamlinkt.test/drafts/01TEST_DRAFT_ID';

    private ?Throwable $throws = null;

    public function getComponentSchema(): array
    {
        return [];
    }

    public function createDraftSite(string $orgId, array $puck, array $provisioning): array
    {
        $this->calls[] = [
            'org_id' => $orgId,
            'puck' => $puck,
            'provisioning' => $provisioning,
        ];

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return [
            'draft_id' => $this->nextDraftId,
            'draft_url' => $this->nextDraftUrl,
        ];
    }

    public function returns(string $draftId, string $draftUrl): void
    {
        $this->nextDraftId = $draftId;
        $this->nextDraftUrl = $draftUrl;
    }

    public function throwOnNextCall(Throwable $e): void
    {
        $this->throws = $e;
    }
}
