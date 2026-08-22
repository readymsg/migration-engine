<?php

declare(strict_types=1);

namespace App\Services\ContractEmitter;

use App\Data\Brand;
use App\Data\ConversionResult;
use App\Data\GlobalStyleBrief;
use App\Data\SiteImport\Diagnostic;
use App\Data\SiteImport\SiteSettings;
use LogicException;
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
// LOUD FALLBACK: every slot (primary/neutral) emits ONE Diagnostic
// per emit call naming its source (measured | llm_guess | missing)
// and, on llm_guess, the reason the measured source was unavailable
// (from Brand.palette_error). Silent falling from measured to LLM
// on the highest-value fields in the contract is the silent-loss
// surface this closes.
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
    public function emit(ConversionResult $result, AssetLedger $ledger): SiteSettingsEmitResult
    {
        $diagnostics = [];
        [$primary, $primarySource, $primaryReason] = $this->resolveSlot(
            slot: 'primary',
            brand: $result->brand,
            brief: $result->style_brief,
        );
        [$neutral, $neutralSource, $neutralReason] = $this->resolveSlot(
            slot: 'text',
            brand: $result->brand,
            brief: $result->style_brief,
        );

        $diagnostics[] = $this->paletteSlotDiagnostic('primary', $primary, $primarySource, $primaryReason);
        $diagnostics[] = $this->paletteSlotDiagnostic('neutral', $neutral, $neutralSource, $neutralReason);

        $settings = new SiteSettings(
            siteName: $this->siteName($result),
            logoUrl: $this->logoToken($result->brand, $ledger),
            favicon: new Optional, // BrandExtractor tracks favicon separately; wiring it up is a follow-up
            primaryColor: $primary ?? new Optional,
            neutralColor: $neutral ?? new Optional,
        );

        return new SiteSettingsEmitResult(settings: $settings, diagnostics: $diagnostics);
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
     * Returns [colorOrNull, source, reasonOrNull] where source is one
     * of 'measured' | 'llm_guess' | 'missing' and reason names why the
     * measured source was unavailable when source === 'llm_guess'.
     *
     * @return array{0: ?string, 1: 'measured'|'llm_guess'|'missing', 2: ?string}
     */
    private function resolveSlot(string $slot, Brand $brand, GlobalStyleBrief $brief): array
    {
        $measured = $brand->palette[$slot] ?? null;
        if (is_string($measured) && $measured !== '') {
            return [$measured, 'measured', null];
        }

        $llm = $brief->palette[$slot] ?? null;
        if (is_string($llm) && $llm !== '') {
            // Fallback used. Name the reason if we have one — Brand's
            // palette_error is populated by BrandExtractor whenever a
            // measurement attempt failed. `null` here means no logo was
            // available to measure at all (flag path).
            $reason = $brand->palette_error ?? 'no_logo_measured';

            return [$llm, 'llm_guess', $reason];
        }

        return [null, 'missing', null];
    }

    /**
     * @param  'measured'|'llm_guess'|'missing'  $source
     */
    private function paletteSlotDiagnostic(string $slot, ?string $color, string $source, ?string $reason): Diagnostic
    {
        return match ($source) {
            'measured' => new Diagnostic(
                severity: 'info',
                code: 'palette_'.$slot.'_from_measured',
                message: "SiteSettings.{$slot}Color = {$color} — measured deterministically from the logo bytes (LogoPaletteExtractor).",
            ),
            'llm_guess' => new Diagnostic(
                severity: 'warning',
                code: 'palette_'.$slot.'_from_llm_guess',
                message: sprintf(
                    'SiteSettings.%sColor = %s — measured palette unavailable (%s); fell back to GlobalStyleBrief\'s LLM-inferred palette. Contract Part II calls this the highest-value field; reviewer should verify.',
                    $slot,
                    $color,
                    $reason ?? 'unknown',
                ),
            ),
            'missing' => new Diagnostic(
                severity: 'warning',
                code: 'palette_'.$slot.'_missing',
                message: "SiteSettings.{$slot}Color left unset — neither measured palette nor LLM brief provided a value.",
            ),
            default => throw new LogicException("unreachable palette source: {$source}"),
        };
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
