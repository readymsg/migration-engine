<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Shared-token gate for the demo trigger endpoint. NOT a login system —
// a single secret from env (`DEMO_TOKEN`) that every valid caller sends
// as `X-Demo-Token`. Missing header, wrong value, or unset env → 401.
//
// Deliberately simple for the demo cut. Per-user auth + rate limits per
// account are production concerns and deferred (see CLAUDE.md step-6
// scope).
final class EnsureDemoToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('services.conversion.demo_token');
        if (! is_string($configured) || $configured === '') {
            // Env unset → refuse rather than accept anything. Prevents
            // an accidental prod deploy from having a wide-open trigger.
            return response()->json([
                'error' => 'demo trigger disabled: DEMO_TOKEN env not configured on the server',
            ], 503);
        }

        $provided = (string) $request->header('X-Demo-Token', '');
        if (! hash_equals($configured, $provided)) {
            return response()->json([
                'error' => 'invalid or missing X-Demo-Token header',
            ], 401);
        }

        return $next($request);
    }
}
