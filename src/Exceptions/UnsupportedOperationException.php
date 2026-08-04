<?php

declare(strict_types=1);

namespace Paysasa\Payments\Exceptions;

/**
 * Thrown when a capability (e.g. refund(), authorize()) is called on a
 * driver whose provider doesn't support it, e.g. Refundable::refund() on a
 * B2C-only M-Pesa flow. Callers can type-check `instanceof Refundable`
 * before calling to avoid this at runtime.
 */
class UnsupportedOperationException extends PaymentException
{
    public static function make(string $driver, string $operation): self
    {
        return new self("Driver [{$driver}] does not support the [{$operation}] operation.", [
            'driver' => $driver,
            'operation' => $operation,
        ]);
    }
}
