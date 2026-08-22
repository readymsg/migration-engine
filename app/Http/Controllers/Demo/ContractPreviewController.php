<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use JsonException;

// M1 preview controller: renders the TeamLinkt Site Import Contract v1
// envelope. Reads storage/app/public/preview/{slug}-contract.json —
// produced by `engine:emit-contract-fixture`. Same JSON-served-to-
// React shape as the old PreviewController, but pointed at the
// contract fixture and rendering with contract-shaped block props.
//
// The old /preview/{slug} route stays alive for regression
// comparison; Slice 18 retires it once M1 stabilises.
final class ContractPreviewController extends Controller
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

        return view('preview-contract', ['slug' => $slug]);
    }

    public function envelope(string $slug): JsonResponse|Response
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
            return response("Could not read contract fixture: {$path}", 500);
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return response("Contract fixture is not valid JSON: {$e->getMessage()}", 500);
        }

        return response()->json($decoded);
    }

    private function fixturePath(string $slug): string
    {
        return storage_path('app/public/preview/'.basename($slug).'-contract.json');
    }

    private function missingFixtureMessage(string $slug, string $path): string
    {
        return <<<TXT
            Contract preview fixture not found for slug '{$slug}'.

            Expected at: {$path}

            Run: php artisan engine:emit-contract-fixture --source-fixture={$slug}
            TXT;
    }
}
