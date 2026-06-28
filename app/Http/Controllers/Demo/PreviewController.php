<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use JsonException;

// THROWAWAY (BUILD.md step 7). Serves the Vite + React + @measured/puck
// bundle that renders a ConversionResult produced by engine:emit-preview-
// fixture. Deleted at graduation when the real product builder/preview
// takes over.
//
// The shape served by site() deliberately mirrors the eventual
// GET /api/demo/{id}/site that step 6 will produce off a Conversion
// model — so the React bundle won't change when the static file is
// replaced by a Conversion-id-keyed read.
//
// v1: only the 'tbirdhoops' slug is wired (the only captured fixture).
// Multi-slug support is out of scope until more fixtures land.
final class PreviewController extends Controller
{
    public function show(string $slug): View|Response
    {
        $path = $this->fixturePath($slug);
        if (! is_file($path)) {
            return response(
                $this->missingFixtureMessage($slug, $path),
                404,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        return view('preview', ['slug' => $slug]);
    }

    public function site(string $slug): JsonResponse|Response
    {
        $path = $this->fixturePath($slug);
        if (! is_file($path)) {
            return response(
                $this->missingFixtureMessage($slug, $path),
                404,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return response("Could not read preview fixture: {$path}", 500);
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return response("Preview fixture is not valid JSON: {$e->getMessage()}", 500);
        }

        return response()->json($decoded);
    }

    private function fixturePath(string $slug): string
    {
        return storage_path('app/public/preview/'.basename($slug).'.json');
    }

    private function missingFixtureMessage(string $slug, string $path): string
    {
        return <<<TXT
            Preview fixture not found for slug '{$slug}'.

            Expected at: {$path}

            Run: php artisan engine:emit-preview-fixture

            (The preview namespace is throwaway — BUILD.md step 7. Only the
            'tbirdhoops' slug is wired in v1.)
            TXT;
    }
}
