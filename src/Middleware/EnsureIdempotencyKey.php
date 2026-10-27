<?php

declare(strict_types=1);

namespace Paysasa\Payments\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional middleware for host applications exposing their own HTTP API on
 * top of this package (e.g. `POST /api/payments`). Requires an
 * `X-Idempotency-Key` header on mutating requests, rather than letting a
 * caller silently omit it and lose duplicate-submission protection. Not
 * applied automatically to anything — attach it to your own routes.
 */
class EnsureIdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        // Any state-changing method, and a non-blank value: `hasHeader()` alone
        // accepts `X-Idempotency-Key:` with nothing in it, which protects nothing.
        $key = $request->header('X-Idempotency-Key');

        if (! $request->isMethodSafe() && (! is_string($key) || trim($key) === '')) {
            return response()->json([
                'message' => 'The X-Idempotency-Key header is required for this request.',
            ], 422);
        }

        return $next($request);
    }
}
