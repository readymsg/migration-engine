<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

// THROWAWAY (BUILD.md step 7). Preview-only asset resolver — reads
// s3://-shaped keys out of query params and either serves the local
// file (self-hosted, the invariant AssetUrlRewriter exists to enforce)
// or falls back to fetching the original CDN URL (visible fallback,
// never mistaken for local-hosted).
//
// The rebuilt draft that ProductClient::createDraftSite() receives
// carries the raw s3:// keys — the PRODUCT is responsible for
// resolving them at serve-time. This preview route exists ONLY so a
// reviewer can visually verify the rebuilt page without the real
// product renderer.
//
// PROVENANCE HEADER — every response carries:
//   X-Preview-Asset-Source: local     — served from the storage disk
//   X-Preview-Asset-Source: fallback:<host> — fetched from a CDN
//   X-Preview-Asset-Source: missing   — no local file, no fallback
// So the browser DevTools Network panel is the source of truth for
// "self-hosted and working" vs "still pulling from SportsEngine".
// Fallback fetches also emit a Log::info entry so a reviewer scanning
// server logs sees every CDN hop.
//
// Query params:
//   p (required)  the s3:// key or bare disk path — e.g.
//                 s3://orgs/ngin-63620/content_assets/xyz.jpg. The
//                 s3:// prefix is stripped and the remainder is
//                 resolved on the local disk.
//   f (optional)  the original CDN URL to fetch when local is missing.
//                 The client (asset-resolver.js) inverts
//                 ConversionResult.asset_refs and passes the
//                 source_url in this param.
final class PreviewAssetController extends Controller
{
    /** @var array<int, string> host suffixes fallback fetch will accept — same set AssetUrlRewriter treats as SE-CDN. Anything else is refused as SSRF-adjacent. */
    private const FALLBACK_ALLOWED_HOST_SUFFIXES = [
        '.sportngin.com',
        '.sportsengine.com',
        '.ngin.com',
    ];

    /** @var array<int, string> fallback exact-match hosts */
    private const FALLBACK_ALLOWED_HOSTS = [
        'sportngin.com',
        'assets.ngin.com',
    ];

    public function show(Request $request): Response
    {
        $p = (string) $request->query('p', '');
        $f = (string) $request->query('f', '');
        if ($p === '') {
            return response('missing required query param `p`', 400);
        }

        $diskPath = $this->stripLogicalPrefix($p);
        if ($this->isSafeDiskPath($diskPath) && Storage::disk('local')->exists($diskPath)) {
            $bytes = (string) Storage::disk('local')->get($diskPath);

            return response(
                $bytes,
                200,
                [
                    'Content-Type' => Storage::disk('local')->mimeType($diskPath) ?: 'application/octet-stream',
                    'X-Preview-Asset-Source' => 'local',
                    'Cache-Control' => 'public, max-age=3600',
                ],
            );
        }

        if ($f === '' || ! $this->isFallbackAllowed($f)) {
            return response(
                sprintf(
                    'asset not on local disk and no fallback URL provided; disk_path=%s fallback=%s',
                    $diskPath,
                    $f === '' ? '(none)' : $f,
                ),
                404,
                ['X-Preview-Asset-Source' => 'missing'],
            );
        }

        // Fallback fetch — record every hop so a reviewer scanning
        // logs sees exactly which assets are still pulling from SE.
        Log::info('preview.asset.fallback', [
            's3_key' => $p,
            'source_url' => $f,
            'reason' => Storage::disk('local')->exists($diskPath)
                ? '(reachable but unsafe path)'
                : 'file not on local disk',
        ]);
        try {
            $upstream = Http::timeout(10)->get($f);
        } catch (Throwable $e) {
            return response(
                'fallback fetch failed: '.$e->getMessage(),
                502,
                ['X-Preview-Asset-Source' => 'missing'],
            );
        }
        if (! $upstream->successful()) {
            return response(
                "fallback fetch returned HTTP {$upstream->status()}",
                502,
                ['X-Preview-Asset-Source' => 'missing'],
            );
        }

        $host = (string) parse_url($f, PHP_URL_HOST);
        $mime = $this->stringHeader($upstream->header('Content-Type')) ?? 'application/octet-stream';

        return response(
            (string) $upstream->body(),
            200,
            [
                'Content-Type' => $mime,
                'X-Preview-Asset-Source' => 'fallback:'.$host,
                'Cache-Control' => 'public, max-age=3600',
            ],
        );
    }

    private function stripLogicalPrefix(string $key): string
    {
        if (str_starts_with($key, 's3://')) {
            return substr($key, 5);
        }

        return ltrim($key, '/');
    }

    // Belt-and-braces path safety — the client controls `p` and could
    // send `..` or absolute paths to try to escape the disk root. The
    // 'local' Laravel disk is rooted at storage/app/ so a normal
    // client request looks like `orgs/ngin-63620/content_assets/x.jpg`.
    // Reject anything with `..` segments or a leading `/`.
    private function isSafeDiskPath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return false;
            }
        }

        return true;
    }

    private function isFallbackAllowed(string $url): bool
    {
        if (! preg_match('#^https?://#i', $url)) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }
        $host = mb_strtolower($host);
        if (in_array($host, self::FALLBACK_ALLOWED_HOSTS, true)) {
            return true;
        }
        foreach (self::FALLBACK_ALLOWED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>|string|null  $header
     */
    private function stringHeader(array|string|null $header): ?string
    {
        if (is_string($header)) {
            return $header;
        }
        if (is_array($header) && isset($header[0]) && is_string($header[0])) {
            return $header[0];
        }

        return null;
    }
}
