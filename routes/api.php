<?php

use App\Http\Controllers\Conversion\ConversionController;
use App\Http\Middleware\EnsureDemoToken;
use Illuminate\Support\Facades\Route;

// Step-6 conversion trigger endpoints. Registered under Laravel's `api`
// route group so CSRF isn't applied (these are JSON API endpoints called
// from external tools + the demo frontend, not stateful browser forms).
// The demo-token gate is our auth surface for now; per-user auth is
// deferred (see CLAUDE.md step-6 deferred list).
//
// Laravel prefixes api.php routes with `api/` automatically, so
// `Route::post('/conversions')` here resolves at `/api/conversions`.
Route::middleware([EnsureDemoToken::class])->prefix('conversions')->group(function (): void {
    Route::post('/', [ConversionController::class, 'trigger'])
        ->middleware('throttle:5,60')
        ->name('conversions.trigger');
    Route::get('/{conversion_id}/status', [ConversionController::class, 'status'])
        ->name('conversions.status');
    Route::get('/{conversion_id}', [ConversionController::class, 'show'])
        ->name('conversions.show');
});
