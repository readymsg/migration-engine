<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Data\ContentRef;
use App\Data\Ir;
use App\Data\IrBlock;
use App\Jobs\GeneratePageJob;
use App\Services\Generate\BlockFillResultStore;
use App\Services\Generate\CacheBlockFillResultStore;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

// Finding 1 fix — process-kill safety net.
//
// Before: $tries=1 meant a worker OOM / timeout SIGKILL / Redis
// eviction (which don't reach handle()'s catch) lost the page
// entirely as "silently absent from result store" — cjfl page-
// 1254223 in the first live run.
//
// After: $tries=3 + failed() safety net. Sonnet-reachable
// exceptions still land in handle()'s existing catch (writes
// BlockFillFailure directly, no retry needed — malformed
// responses reproduce). Process-kill exceptions never reach
// handle() at all; Laravel's queue driver returns the job to
// the queue, retries $tries times, then invokes failed() which
// writes the BlockFillFailure. Reconciliation always sees a
// visible failure, never a silent absence.
//
// These tests pin the failed() hook contract directly (call it
// as a method, assert store state). Same posture as the async
// chaos suite's direct-store manipulation approach — no need
// for a real queue driver to prove the contract.
final class GeneratePageJobFailedHookTest extends TestCase
{
    #[Test]
    public function failed_hook_writes_block_fill_failure_with_attempt_count_in_reason(): void
    {
        $store = new CacheBlockFillResultStore(Cache::store());
        $this->app->instance(BlockFillResultStore::class, $store);

        $job = new GeneratePageJob(
            conversion_id: 'conv-failed-test',
            page_slug: 'page-42',
            ir: new Ir(
                page_slug: 'page-42',
                page_title: 'Test Page',
                nav_order: 0,
                blocks: new DataCollection(IrBlock::class, []),
            ),
            content_ref: new ContentRef(
                url: 'https://example.org/test',
                scrape_ref: 's3://fake/scrape.json',
                title: 'Test Page',
            ),

            org_id: 'ngin-test',
        );

        // Job's default $tries = 3.
        $this->assertSame(3, $job->tries);

        $job->failed(new RuntimeException('anthropic 503 service unavailable'));

        $failure = $store->getFailure('conv-failed-test', 'page-42');
        $this->assertNotNull($failure);
        $this->assertSame('page-42', $failure->page_slug);
        $this->assertSame('Test Page', $failure->page_title);
        $this->assertStringContainsString('after 3 attempts', $failure->reason);
        $this->assertStringContainsString('anthropic 503 service unavailable', $failure->reason);
    }

    #[Test]
    public function failed_hook_handles_null_exception_gracefully(): void
    {
        // Laravel's failed() can be called with null exception in some
        // edge cases (e.g., manual $job->fail()). Still write a
        // BlockFillFailure so reconciliation sees the page.
        $store = new CacheBlockFillResultStore(Cache::store());
        $this->app->instance(BlockFillResultStore::class, $store);

        $job = new GeneratePageJob(
            conversion_id: 'conv-null-exc',
            page_slug: 'page-99',
            ir: new Ir(
                page_slug: 'page-99',
                page_title: 'Null Exception Test',
                nav_order: 0,
                blocks: new DataCollection(IrBlock::class, []),
            ),
            content_ref: new ContentRef(
                url: 'https://example.org/test',
                scrape_ref: 's3://fake/scrape.json',
                title: 'Null Exception Test',
            ),
            org_id: 'ngin-test',
        );

        $job->failed(null);

        $failure = $store->getFailure('conv-null-exc', 'page-99');
        $this->assertNotNull($failure);
        $this->assertStringContainsString('no exception recorded', $failure->reason);
    }

    #[Test]
    public function backoff_schedule_is_configured_for_transient_recovery(): void
    {
        // Backoff pin: 30s then 60s. Long enough that consecutive
        // attempts don't stack against a slow Sonnet response
        // (block-fill wall time is 30-90s per BUILD.md).
        $job = new GeneratePageJob(
            conversion_id: 'x',
            page_slug: 'x',
            ir: new Ir(
                page_slug: 'x',
                page_title: 'X',
                nav_order: 0,
                blocks: new DataCollection(IrBlock::class, []),
            ),
            content_ref: new ContentRef(
                url: 'https://x/x',
                scrape_ref: 's3://fake/scrape.json',
                title: 'X',
            ),
            org_id: 'x',
        );
        $this->assertSame([30, 60], $job->backoff);
    }
}
