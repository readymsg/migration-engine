<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\Brand;
use App\Data\ConversionResult;
use App\Data\GlobalStyleBrief;
use App\Data\SiteImport\SiteSettings;
use Spatie\LaravelData\Optional;

// Emits the contract's SiteSettings.siteName / primaryColor /
// neutralColor / logoUrl / favicon from what we've measured about
// the org. Site Import Contract Part II "What you may set on
// `site`" + the callout that primaryColor + neutralColor are the
// highest-value fields in the whole payload.
//
// PALETTE PRECEDENCE (matches the preview's precedence from Slice
// B in the old preview stack): measured Brand.palette wins over
// LLM-inferred GlobalStyleBrief.palette. The measured palette is
// deterministic; the LLM's is a fresh Opus guess per run and, on
// tbirdhoops, was empirically wrong (#1F3A93 dark blue vs the
// club's actual red #AE292E).
//
// FORBIDDEN KEYS (zones / templateId / theme / showTeamRosters):
// enforced STRUCTURALLY by SiteSettings' DTO shape — those keys
// don't exist as properties on the class, so we can't accidentally
// carry them. No runtime check needed here.
//
// MAPPED FROM OUR EXTRACTOR: primary → primaryColor, text →
// neutralColor. Extractor's secondary / accent / background have
// no contract slot and are dropped from the payload; they remain
// in the preview's debug chrome (see PROVENANCE.md).
final class SiteSettingsEmitter
{
    public function emit(ConversionResult $result, AssetLedger $ledger): SiteSettings
    {
        $palette = $this->activePalette($result->brand, $result->style_brief);

        return new SiteSettings(
            siteName: $this->siteName($result),
            logoUrl: $this->logoToken($result->brand, $ledger),
            favicon: new Optional, // BrandExtractor tracks favicon separately; wiring it up is a follow-up
            primaryColor: $palette['primary'] ?? new Optional,
            neutralColor: $palette['text'] ?? new Optional,
        );
    }

    private function siteName(ConversionResult $result): string|Optional
    {
        // We don't track a display name today. The three plausible
        // fallbacks in order of quality:
        //   1. Brand.voice_hint (rare — extractor keeps it null).
        //   2. Host of source_url (e.g. "tbirdhoops.org" → title-case).
        //   3. org_id (the SE numeric id — worst option).
        if (is_string($result->brand->voice_hint) && $result->brand->voice_hint !== '') {
            return $result->brand->voice_hint;
        }
        $host = parse_url($result->source_url, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            // Strip www. and TLD-ish suffix for readability.
            $host = preg_replace('/^www\./i', '', $host) ?? $host;

            // Not a strong extraction — reviewer will edit. Best we
            // can do without a real display name in the pipeline.
            return $host;
        }

        return new Optional;
    }

    /**
     * @return array{primary?: string, text?: string}
     */
    private function activePalette(Brand $brand, GlobalStyleBrief $brief): array
    {
        $out = [];
        // Measured wins.
        $palette = $brand->palette;
        if (isset($palette['primary']) && is_string($palette['primary'])) {
            $out['primary'] = $palette['primary'];
        }
        if (isset($palette['text']) && is_string($palette['text'])) {
            $out['text'] = $palette['text'];
        }
        // Fall back to LLM only for slots the measured extractor
        // didn't fill.
        $llm = $brief->palette;
        if (! isset($out['primary']) && isset($llm['primary']) && is_string($llm['primary'])) {
            $out['primary'] = $llm['primary'];
        }
        if (! isset($out['text']) && isset($llm['text']) && is_string($llm['text'])) {
            $out['text'] = $llm['text'];
        }

        return $out;
    }

    private function logoToken(Brand $brand, AssetLedger $ledger): string|Optional
    {
        // Brand.logo_source_url is the ORIGINAL CDN URL we captured
        // during ingest. That's what goes into assets[].sourceUrl —
        // NOT the s3_key we rehosted to. This is the same inversion
        // the AssetContext performs for content-block URLs.
        $source = $brand->logo_source_url;
        if (! is_string($source) || $source === '') {
            return new Optional;
        }

        // Infer mime from extension so the ledger's whitelist can
        // accept it. Contract Part II accepts image/jpeg|png|webp|gif
        // and application/pdf; SVG rejected.
        $mime = $this->guessMime($source);
        if ($mime === null) {
            return new Optional;
        }
        $filename = basename((string) parse_url($source, PHP_URL_PATH)) ?: 'logo';

        $token = $ledger->tokenFor(
            sourceUrl: $source,
            filename: $filename,
            mimeType: $mime,
            alt: null,
            usage: 'logo',
        );

        return $token ?? new Optional;
    }

    private function guessMime(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => null,
        };
    }
}
