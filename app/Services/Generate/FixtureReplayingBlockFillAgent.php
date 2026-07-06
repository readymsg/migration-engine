<?php

declare(strict_types=1);

namespace App\Services\Generate;

use App\Data\BlockFillInput;
use App\Data\FilledPage;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use RuntimeException;

// Validation-only BlockFillAgent that returns a pre-recorded FilledPage
// from cache instead of calling Sonnet. Used by the Tier-4 async fixture
// replay (engine:async-fixture-replay) to prove the async orchestration
// works cross-process against real production-shaped payloads WITHOUT
// spending on real Sonnet calls.
//
// Wiring: AppServiceProvider binds this class to BlockFillAgent when
// BLOCKFILL_FIXTURE_REPLAY=1 is in the env. The command sets that env
// on the caller side, but the WORKER PROCESS must be started with the
// same env for the binding to take effect there — the container binding
// is per-process. Start Horizon with:
//
//   BLOCKFILL_FIXTURE_REPLAY=1 php artisan horizon
//
// Then the command populates the cache with the fixture's FilledPages
// (keyed by conversion_id + slug), dispatches, and every worker's
// BlockFillAgent::run() reads the pre-recorded page instead of calling
// Sonnet.
//
// Fail-loud on cache miss: if a slug isn't in the cache, throw with a
// clear reason. The job's Throwable-catch converts this to a
// BlockFillFailure with the miss reason — visible in the reconciled
// result, not silent.
final class FixtureReplayingBlockFillAgent implements BlockFillAgent
{
    private const KEY_PREFIX = 'fixture-replay:page:';

    private const TTL_SECONDS = 3600;

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Populate the cache with a fixture FilledPage. Called by the
     * fixture-replay command BEFORE dispatch, once per slug. All
     * workers subsequently reading via run() will see the same data.
     */
    public function seed(string $conversionId, FilledPage $page): void
    {
        $this->cache->put(
            $this->key($conversionId, $page->page_slug),
            $page->toJson(),
            self::TTL_SECONDS,
        );
    }

    public function run(BlockFillInput $input): FilledPage
    {
        // Convention: the command sets a conversion_id-scoped key. The
        // BlockFillInput doesn't carry conversion_id today, but the
        // fixture-replay command sets a per-conversion prefix via a
        // side-channel: the org_id field. This works because the
        // command's stub Manifest uses `org_id = "ngin-tier4-<conv>"`
        // — the agent reads it back to compose the cache key.
        //
        // Alternate design (would require an interface change): thread
        // conversion_id through BlockFillInput. Deferred as unnecessary
        // for the validation use case.
        $conversionId = $this->conversionIdFromOrgId($input->org_id);
        $key = $this->key($conversionId, $input->page_slug);
        /** @var mixed $raw */
        $raw = $this->cache->get($key);
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException(
                "FixtureReplayingBlockFillAgent: no cached FilledPage for slug '{$input->page_slug}' "
                ."under conversion '{$conversionId}' (key: {$key}). "
                .'Command must seed the cache before dispatching.'
            );
        }

        return FilledPage::from(json_decode($raw, associative: true));
    }

    private function key(string $conversionId, string $pageSlug): string
    {
        return self::KEY_PREFIX.$conversionId.':'.$pageSlug;
    }

    /**
     * The command's stub manifest uses `org_id = "ngin-tier4-<conversion>"`
     * to thread conversion identity through to the agent without changing
     * the BlockFillInput contract. Parse it out here.
     */
    private function conversionIdFromOrgId(string $orgId): string
    {
        if (str_starts_with($orgId, 'ngin-tier4-')) {
            return substr($orgId, strlen('ngin-tier4-'));
        }

        return $orgId;
    }
}
