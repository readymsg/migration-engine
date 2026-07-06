<?php

use App\Http\Controllers\Demo\LandingController;
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

// The `/api/conversions/*` trigger routes live in routes/api.php so
// they bypass CSRF (JSON API, not browser-form). See routes/api.php.
