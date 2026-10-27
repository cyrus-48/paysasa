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
 * IPs in `trusted_ips` and the M-Pesa webhook IP allowlist are exempt.
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

        // Safaricom delivers every merchant's callbacks from a handful of shared
        // IPs; throttling them would drop real payment callbacks. Those IPs are
        // already authenticated by the M-Pesa allowlist, so they are exempt.
        $trusted = array_merge(
            (array) ($config['trusted_ips'] ?? []),
            (array) config('paysasa.drivers.mpesa.webhook_ip_allowlist', []),
        );

        if ($request->ip() !== null && in_array($request->ip(), $trusted, true)) {
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
