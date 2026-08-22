<?php

declare(strict_types=1);

namespace Tests\Unit\ContractEmitter;

use App\Data\SiteImport\Asset;
use App\Services\ContractEmitter\AssetLedger;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\Optional;
use Tests\TestCase;

// Pins the asset ledger against Contract Part II "Assets" rules.
// The load-bearing property: our S3 keys never appear in the
// payload, and every token has a matching entry BY CONSTRUCTION.
final class AssetLedgerTest extends TestCase
{
    // ─── happy path ──────────────────────────────────────────────────────

    #[Test]
    public function accepts_jpeg_and_returns_tl_asset_token(): void
    {
        $ledger = new AssetLedger;
        $result = $ledger->register(
            sourceUrl: 'https://cdn2.sportngin.com/attachments/photo/64f2/rink.jpg',
            filename: 'rink.jpg',
            mimeType: 'image/jpeg',
            alt: 'Community rink',
            usage: 'hero',
        );
        $this->assertFalse($result->rejected);
        $this->assertNotNull($result->ref);
        // Ref grammar: [a-z0-9-]{1,64}
        $this->assertMatchesRegularExpression('/^[a-z0-9-]{1,64}$/', $result->ref);
        // Deterministic: same URL → same ref.
        $again = $ledger->register(
            sourceUrl: 'https://cdn2.sportngin.com/attachments/photo/64f2/rink.jpg',
            filename: 'rink.jpg',
            mimeType: 'image/jpeg',
        );
        $this->assertSame($result->ref, $again->ref, 'deterministic: same sourceUrl → same ref');
    }

    #[Test]
    public function usage_hint_prefixes_the_ref(): void
    {
        $ledger = new AssetLedger;
        $withUsage = $ledger->register('https://x.com/a.png', 'a.png', 'image/png', usage: 'logo');
        $noUsage = $ledger->register('https://x.com/b.png', 'b.png', 'image/png');
        $this->assertStringStartsWith('logo-', (string) $withUsage->ref);
        $this->assertStringStartsWith('asset-', (string) $noUsage->ref);
    }

    #[Test]
    public function all_accepted_mime_types_are_accepted(): void
    {
        $ledger = new AssetLedger;
        $mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
        foreach ($mimes as $i => $mime) {
            $r = $ledger->register("https://x.com/f{$i}", "f{$i}", $mime);
            $this->assertFalse($r->rejected, "{$mime} must be accepted");
        }
    }

    #[Test]
    public function dedupes_by_source_url(): void
    {
        $ledger = new AssetLedger;
        // Two different props reference the same source URL — should
        // register once, be returned twice.
        $a = $ledger->register('https://x.com/logo.png', 'logo.png', 'image/png', usage: 'logo');
        $b = $ledger->register('https://x.com/logo.png', 'logo.png', 'image/png', usage: 'hero'); // usage hint ignored on dedupe
        $this->assertSame($a->ref, $b->ref);
        $this->assertSame(1, $ledger->count());
    }

    #[Test]
    public function token_for_returns_full_prefixed_token(): void
    {
        $ledger = new AssetLedger;
        $token = $ledger->tokenFor(
            sourceUrl: 'https://x.com/hero.jpg',
            filename: 'hero.jpg',
            mimeType: 'image/jpeg',
            usage: 'hero',
        );
        $this->assertNotNull($token);
        $this->assertStringStartsWith('tl-asset:', $token);
        // The ref after the prefix should be valid grammar.
        $ref = substr($token, strlen('tl-asset:'));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]{1,64}$/', $ref);
    }

    #[Test]
    public function all_returns_data_collection_in_registration_order(): void
    {
        $ledger = new AssetLedger;
        $ledger->register('https://x.com/1', 'one', 'image/png');
        $ledger->register('https://x.com/2', 'two', 'image/jpeg');
        $ledger->register('https://x.com/3', 'three', 'application/pdf');

        $all = $ledger->all();
        $this->assertCount(3, $all);
        /** @var array<int, Asset> $items */
        $items = $all->items();
        $this->assertSame('one', $items[0]->filename);
        $this->assertSame('two', $items[1]->filename);
        $this->assertSame('three', $items[2]->filename);
    }

    #[Test]
    public function alt_and_usage_are_optional_and_omitted_when_unset(): void
    {
        $ledger = new AssetLedger;
        $ledger->register('https://x.com/plain.png', 'plain.png', 'image/png');
        /** @var Asset $asset */
        $asset = $ledger->all()->items()[0];
        $this->assertInstanceOf(Optional::class, $asset->alt);
        $this->assertInstanceOf(Optional::class, $asset->usage);
    }

    #[Test]
    public function alt_and_usage_are_carried_through_when_set(): void
    {
        $ledger = new AssetLedger;
        $ledger->register(
            sourceUrl: 'https://x.com/hero.jpg',
            filename: 'hero.jpg',
            mimeType: 'image/jpeg',
            alt: 'Players on ice',
            usage: 'hero',
        );
        /** @var Asset $asset */
        $asset = $ledger->all()->items()[0];
        $this->assertSame('Players on ice', $asset->alt);
        $this->assertSame('hero', $asset->usage);
    }

    // ─── rejection cases — each becomes a diagnostic upstream ────────────

    #[Test]
    public function svg_is_rejected_with_stored_xss_reason(): void
    {
        // Contract Part II: "SVG is not accepted. An SVG is a
        // script-capable document ... stored-XSS vector".
        $ledger = new AssetLedger;
        $r = $ledger->register('https://x.com/logo.svg', 'logo.svg', 'image/svg+xml');
        $this->assertTrue($r->rejected);
        $this->assertNotNull($r->reason);
        $this->assertStringContainsString('SVG', $r->reason);
        $this->assertStringContainsString('XSS', $r->reason);
        $this->assertSame(0, $ledger->count(), 'rejected assets must NOT enter the ledger');
    }

    #[Test]
    public function non_whitelisted_mime_is_rejected(): void
    {
        $ledger = new AssetLedger;
        foreach (['image/tiff', 'video/mp4', 'text/html', 'application/zip'] as $bad) {
            $r = $ledger->register('https://x.com/f', 'f', $bad);
            $this->assertTrue($r->rejected, "{$bad} must be rejected");
            $this->assertStringContainsString($bad, (string) $r->reason);
        }
    }

    #[Test]
    public function scheme_less_source_url_is_rejected(): void
    {
        // Contract Part II: sourceUrl must be absolute, publicly
        // fetchable. TeamLinkt fetches server-side and a scheme-
        // less URL wouldn't resolve there.
        $ledger = new AssetLedger;
        $r = $ledger->register('//cdn.example.com/x.png', 'x.png', 'image/png');
        $this->assertTrue($r->rejected);
        $this->assertStringContainsString('absolute', (string) $r->reason);
    }

    #[Test]
    public function s3_scheme_source_url_is_rejected(): void
    {
        // Load-bearing: our OWN s3:// keys must NOT enter the
        // ledger. If someone accidentally passes a Manifest s3_key
        // instead of the source_url, we reject rather than silently
        // hotlinking our storage.
        $ledger = new AssetLedger;
        $r = $ledger->register('s3://engine-bucket/orgs/x/logos/abc.png', 'abc.png', 'image/png');
        $this->assertTrue($r->rejected);
    }

    #[Test]
    public function empty_source_url_is_rejected(): void
    {
        $ledger = new AssetLedger;
        $r = $ledger->register('', 'f', 'image/png');
        $this->assertTrue($r->rejected);
    }

    #[Test]
    public function token_for_returns_null_on_rejection(): void
    {
        $ledger = new AssetLedger;
        $token = $ledger->tokenFor('https://x.com/x.svg', 'x.svg', 'image/svg+xml');
        $this->assertNull($token, 'token must be null when the underlying asset is rejected');
    }

    // ─── ref grammar edge cases ──────────────────────────────────────────

    #[Test]
    public function invalid_usage_hint_falls_back_to_asset_prefix(): void
    {
        // Contract ref grammar is [a-z0-9-]{1,64}. If a caller passes
        // "Hero Image!" (capital + space + bang), the ledger must
        // NOT emit an invalid ref — fall back to "asset-".
        $ledger = new AssetLedger;
        $r = $ledger->register('https://x.com/a.png', 'a.png', 'image/png', usage: 'Hero Image!');
        $this->assertFalse($r->rejected);
        $this->assertNotNull($r->ref);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]{1,64}$/', $r->ref);
        $this->assertStringStartsWith('asset-', $r->ref);
    }
}
