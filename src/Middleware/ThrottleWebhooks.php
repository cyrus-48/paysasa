<?php

declare(strict_types=1);

namespace Paysasa\Payments\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate-limits inbound webhook traffic per source IP, independent of any
 * throttling the host app applies to its own routes — protects the
 * package's webhook endpoints from being used as a DoS vector or brute-force
 * probe surface, configurable via config('paysasa.rate_limiting.webhooks').
 */
class ThrottleWebhooks
{
    public function __construct(protected RateLimiter $limiter)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $config = config('paysasa.rate_limiting.webhooks', []);

        if (! ($config['enabled'] ?? true)) {
            return $next($request);
        }

        $key = 'paysasa:webhook-throttle:'.$request->ip();
        $maxAttempts = $config['max_attempts'] ?? 120;
        $decaySeconds = $config['decay_seconds'] ?? 60;

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            return response()->json(['message' => 'Too many webhook requests'], 429);
        }

        $this->limiter->hit($key, $decaySeconds);

        return $next($request);
    }
}
