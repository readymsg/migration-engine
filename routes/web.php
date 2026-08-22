<?php

use App\Http\Controllers\Demo\ContractPreviewController;
use App\Http\Controllers\Demo\LandingController;
use App\Http\Controllers\Demo\PreviewAssetController;
use App\Http\Controllers\Demo\PreviewController;
use Illuminate\Support\Facades\Route;

// Hosted-demo landing page: paste an allowlisted SportsEngine URL, click
// Convert, watch it go, land on the preview. See LandingController for
// the token + allowlist embedding model.
Route::get('/', [LandingController::class, 'show'])->name('landing');

// THROWAWAY (still): Demo/Preview namespace. Serves the React+Puck
// bundle. Handles BOTH shapes:
//   /preview/{slug}      → static fixture at storage/app/public/preview/{slug}.json
//                          (kept alive for the tbirdhoops demo)
//   /preview/conv-<id>   → live conversion, reads ConversionResult from cache
// The React bundle consumes the same JSON shape from either path.
Route::get('/preview/{slug}', [PreviewController::class, 'show'])->name('preview.show');
Route::get('/api/preview/{slug}/site', [PreviewController::class, 'site'])->name('preview.site');

// Slice 10 (M1): contract-shaped preview. Renders the Site Import
// Contract v1 Envelope emitted by engine:emit-contract-fixture.
// The old /preview/{slug} above stays alive for regression
// comparison; Slice 18 retires it once M1 stabilises.
Route::get('/preview-contract/{slug}', [ContractPreviewController::class, 'show'])->name('preview.contract.show');
Route::get('/api/preview-contract/{slug}/envelope', [ContractPreviewController::class, 'envelope'])->name('preview.contract.envelope');

// THROWAWAY: preview-only asset resolver. Serves s3://-shaped keys
// out of query params (?p={s3-key}&f={optional-source-url}) — local
// disk first, fallback to CDN with a visible X-Preview-Asset-Source
// header. See PreviewAssetController for the provenance contract.
Route::get('/preview-assets', [PreviewAssetController::class, 'show'])->name('preview.asset');

// The `/api/conversions/*` trigger routes live in routes/api.php so
// they bypass CSRF (JSON API, not browser-form). See routes/api.php.
