<?php

declare(strict_types=1);

namespace Paysasa\Payments\Support;

use Illuminate\Support\Facades\Cache;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Exceptions\IdempotencyConflictException;

/**
 * Guards charge() calls against duplicate submission — a customer
 * double-tapping "Pay", a client retrying after a network timeout, or a
 * queued job replaying after a crash. Backed by the cache store configured
 * in config('paysasa.idempotency.store') so it works correctly across a
 * horizontally-scaled app.
 *
 * Contract: same idempotency key + same request fingerprint within the TTL
 * -> return the cached response without re-calling the provider. Same key
 * + different fingerprint -> IdempotencyConflictException (something is
 * wrong with the caller, e.g. key reuse across unrelated invoices).
 */
class IdempotencyManager
{
    public function __construct(protected array $config)
    {
    }

    protected function enabled(): bool
    {
        return $this->config['enabled'] ?? true;
    }

    protected function store()
    {
        return Cache::store($this->config['store'] ?? null);
    }

    protected function cacheKey(string $idempotencyKey): string
    {
        return "paysasa:idempotency:{$idempotencyKey}";
    }

    /**
     * @throws IdempotencyConflictException
     */
    public function remember(ChargeRequest $request, \Closure $callback): PaymentResponse
    {
        if (! $this->enabled() || $request->idempotencyKey === null) {
            return $callback();
        }

        $key = $this->cacheKey($request->idempotencyKey);
        $fingerprint = $request->fingerprint();

        $cached = $this->store()->get($key);

        if ($cached !== null) {
            if ($cached['fingerprint'] !== $fingerprint) {
                throw IdempotencyConflictException::payloadMismatch($request->idempotencyKey);
            }

            return $cached['response'];
        }

        $response = $callback();

        $this->store()->put($key, [
            'fingerprint' => $fingerprint,
            'response' => $response,
        ], now()->addSeconds($this->config['ttl_seconds'] ?? 86400));

        return $response;
    }
}
