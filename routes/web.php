<?php

use App\Http\Controllers\Demo\PreviewController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// THROWAWAY: Demo/Preview namespace per BUILD.md step 7. Renders a
// ConversionResult produced by engine:emit-preview-fixture against the
// default Puck schema. Deleted at graduation. Only 'tbirdhoops' is
// wired today (the only captured fixture).
Route::get('/preview/{slug}', [PreviewController::class, 'show'])->name('preview.show');
Route::get('/api/preview/{slug}/site', [PreviewController::class, 'site'])->name('preview.site');
