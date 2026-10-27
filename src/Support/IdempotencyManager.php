<?php

declare(strict_types=1);

namespace Paysasa\Payments\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Exceptions\IdempotencyConflictException;
use Paysasa\Payments\Models\Payment;

/**
 * Guards charge() calls against duplicate submission — a customer
 * double-tapping "Pay", a client retrying after a network timeout, or a
 * queued job replaying after a crash.
 *
 * Contract: same idempotency key + same request fingerprint within the TTL
 * -> return the stored result without re-calling the provider. Same key
 * + different fingerprint -> IdempotencyConflictException.
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
        $lockKey = "paysasa:idem:lock:{$request->idempotencyKey}";

        $runner = function () use ($request, $key, $fingerprint, $callback) {
            $cached = $this->store()->get($key);

            if ($cached !== null) {
                if (($cached['fingerprint'] ?? null) !== $fingerprint) {
                    throw IdempotencyConflictException::payloadMismatch($request->idempotencyKey);
                }

                if (isset($cached['payment_id'])) {
                    $payment = Payment::find($cached['payment_id']);
                    if ($payment !== null) {
                        return PaymentResponse::fromPayment($payment, replayed: true);
                    }
                }

                if (isset($cached['response']) && $cached['response'] instanceof PaymentResponse) {
                    return $cached['response'];
                }
            }

            // DB source of truth
            $existing = Payment::query()->where('idempotency_key', $request->idempotencyKey)->first();
            if ($existing !== null) {
                return PaymentResponse::fromPayment($existing, replayed: true);
            }

            try {
                $response = $callback();
            } catch (UniqueConstraintViolationException $e) {
                $existing = Payment::query()->where('idempotency_key', $request->idempotencyKey)->first();
                if ($existing !== null) {
                    return PaymentResponse::fromPayment($existing, replayed: true);
                }

                throw $e;
            }

            $payment = Payment::query()->where('idempotency_key', $request->idempotencyKey)->first();

            $this->store()->put($key, [
                'fingerprint' => $fingerprint,
                'payment_id' => $payment?->id,
                'response' => $response,
            ], now()->addSeconds((int) ($this->config['ttl_seconds'] ?? 86400)));

            return $response;
        };

        $lock = $this->acquireLock($lockKey);

        // No lock available (store without lock support, lock timeout, or a
        // cache outage): fall back to the database, which is the source of
        // truth — the unique idempotency_key column stops a duplicate row.
        if ($lock === null) {
            return $runner();
        }

        // Exceptions from $runner are the caller's to handle. They must NOT be
        // swallowed and retried here: re-running a charge callback after it has
        // already created a Payment row would hide the real error and return a
        // "replayed" pending response for a payment that never reached the
        // provider.
        try {
            return $runner();
        } finally {
            $lock->release();
        }
    }

    /**
     * Try to take the per-key lock. Returns null — never throws — when the
     * lock cannot be acquired, so the caller can fall back to the DB check.
     */
    protected function acquireLock(string $lockKey): ?Lock
    {
        $cacheStore = $this->store()->getStore();

        if (! $cacheStore instanceof LockProvider) {
            return null;
        }

        try {
            $lock = $cacheStore->lock($lockKey, 30);

            return $lock->block(5) ? $lock : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
