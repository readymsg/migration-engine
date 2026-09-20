<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Services\Assets\Exceptions\SvgRasterizationFailed;
use App\Services\Assets\Exceptions\SvgRasterizerUnavailable;
use SimpleXMLElement;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

// SVG → PNG rasterizer. Shells to `rsvg-convert` (librsvg).
//
// v2 §9 requires PNG ≥ 512 px on the long edge for scraped SVG logos
// (SVG itself is rejected as a stored-XSS vector at ingest). We read
// the SVG's declared viewBox / width / height, scale so the long edge
// meets the minimum, and hand the resulting PNG bytes back for the
// normal accept-mime path.
//
// PATH RESOLUTION: `config('services.svg_rasterizer.path')`, else
// `/opt/homebrew/bin/rsvg-convert` (macOS Homebrew default). Forge
// (Ubuntu) will typically use `/usr/bin/rsvg-convert` — set
// `SVG_RASTERIZER_PATH=/usr/bin/rsvg-convert` in the env.
//
// FAILURE POSTURE:
//   - Binary missing / non-executable → `SvgRasterizerUnavailable`
//     (caller falls back to `svg_rasterizer_missing` sidecar).
//   - Non-zero exit, timeout, empty output → `SvgRasterizationFailed`
//     with stderr in the message (caller surfaces via sidecar too).
// The distinction matters: "missing" is an infra problem (fix the
// deploy), "failed" is a per-asset problem (malformed SVG etc.).
final class SvgRasterizer
{
    private const DEFAULT_BINARY = '/opt/homebrew/bin/rsvg-convert';

    private const PROCESS_TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private readonly ?string $binaryPath = null,
    ) {}

    /**
     * Rasterize the given SVG bytes to PNG with long edge ≥ $minLongEdge.
     *
     * Returns raw PNG bytes. Never returns empty on success.
     */
    public function rasterize(string $svgBytes, int $minLongEdge = 512): string
    {
        if ($svgBytes === '') {
            throw new SvgRasterizationFailed('empty SVG input');
        }

        $binary = $this->resolveBinary();

        [$targetWidth, $targetHeight] = $this->targetDimensions($svgBytes, $minLongEdge);

        $args = [$binary, '--format=png'];
        if ($targetWidth !== null) {
            $args[] = '--width='.$targetWidth;
        }
        if ($targetHeight !== null) {
            $args[] = '--height='.$targetHeight;
        }
        // Keep aspect when we set only one edge; librsvg does this by
        // default. We deliberately set only the LONG edge (whichever
        // that is) so the OTHER edge scales proportionally.

        $process = new Process($args);
        $process->setInput($svgBytes);
        $process->setTimeout(self::PROCESS_TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new SvgRasterizationFailed('rsvg-convert timed out after '.self::PROCESS_TIMEOUT_SECONDS.'s', 0, $e);
        } catch (Throwable $e) {
            throw new SvgRasterizationFailed('rsvg-convert failed to start: '.$e->getMessage(), 0, $e);
        }

        if (! $process->isSuccessful()) {
            throw new SvgRasterizationFailed('rsvg-convert exited '.$process->getExitCode().': '.trim($process->getErrorOutput()));
        }

        $png = $process->getOutput();
        if ($png === '') {
            throw new SvgRasterizationFailed('rsvg-convert produced empty output');
        }

        return $png;
    }

    private function resolveBinary(): string
    {
        $configured = $this->binaryPath ?? (string) config('services.svg_rasterizer.path', self::DEFAULT_BINARY);
        if ($configured === '' || ! is_file($configured) || ! is_executable($configured)) {
            throw new SvgRasterizerUnavailable('rsvg-convert not found or not executable at '.($configured === '' ? '<empty>' : $configured));
        }

        return $configured;
    }

    /**
     * Pick target width and/or height so the long edge ≥ $minLongEdge.
     *
     * Reads the SVG's viewBox first (most reliable), then width/height
     * attributes, then falls back to `--width=$minLongEdge` alone
     * (librsvg preserves aspect from viewBox even without both edges).
     *
     * @return array{0: ?int, 1: ?int} [width, height] — either may be null
     */
    private function targetDimensions(string $svgBytes, int $minLongEdge): array
    {
        $intrinsic = $this->readIntrinsicDimensions($svgBytes);
        if ($intrinsic === null) {
            // No intrinsic dims — set width only; librsvg uses viewBox
            // for aspect (or fails, which we surface as failure above).
            return [$minLongEdge, null];
        }

        [$w, $h] = $intrinsic;
        if ($w <= 0.0 || $h <= 0.0) {
            return [$minLongEdge, null];
        }

        if ($w >= $h) {
            $scale = $minLongEdge / $w;
            $targetW = $minLongEdge;
            $targetH = (int) max(1, round($h * $scale));

            return [$targetW, $targetH];
        }

        $scale = $minLongEdge / $h;
        $targetH = $minLongEdge;
        $targetW = (int) max(1, round($w * $scale));

        return [$targetW, $targetH];
    }

    /**
     * @return array{0: float, 1: float}|null intrinsic [width, height] in pixel units
     */
    private function readIntrinsicDimensions(string $svgBytes): ?array
    {
        // libxml_use_internal_errors so a malformed SVG doesn't
        // spam warnings — rasterize() catches the process failure
        // downstream if it's really broken.
        $previousErrorState = libxml_use_internal_errors(true);
        try {
            $xml = @new SimpleXMLElement($svgBytes);
        } catch (Throwable) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorState);

            return null;
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorState);

        $viewBox = (string) ($xml['viewBox'] ?? '');
        if ($viewBox !== '') {
            $parts = preg_split('/[\s,]+/', trim($viewBox)) ?: [];
            if (count($parts) === 4) {
                $w = (float) $parts[2];
                $h = (float) $parts[3];
                if ($w > 0.0 && $h > 0.0) {
                    return [$w, $h];
                }
            }
        }

        $widthAttr = self::parseLength((string) ($xml['width'] ?? ''));
        $heightAttr = self::parseLength((string) ($xml['height'] ?? ''));
        if ($widthAttr !== null && $heightAttr !== null) {
            return [$widthAttr, $heightAttr];
        }

        return null;
    }

    private static function parseLength(string $raw): ?float
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        // Accept "512", "512px", "512.5". Reject percentages (relative,
        // can't derive a target from them without a container context).
        if (preg_match('/^(\d+(?:\.\d+)?)(?:px)?$/i', $trimmed, $m) !== 1) {
            return null;
        }
        $val = (float) $m[1];

        return $val > 0.0 ? $val : null;
    }
}
