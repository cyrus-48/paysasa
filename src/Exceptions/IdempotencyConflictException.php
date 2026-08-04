<?php

declare(strict_types=1);

namespace Paysasa\Payments\Exceptions;

/**
 * Thrown when the same idempotency key is reused with a materially
 * different request payload (amount, currency, reference). A retried
 * request with an identical payload does NOT throw this — it returns the
 * original cached PaymentResponse instead.
 */
class IdempotencyConflictException extends PaymentException
{
    public static function payloadMismatch(string $key): self
    {
        return new self("Idempotency key [{$key}] was already used with a different request payload.", [
            'idempotency_key' => $key,
        ]);
    }
}
