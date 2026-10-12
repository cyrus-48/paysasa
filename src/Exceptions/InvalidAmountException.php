<?php

declare(strict_types=1);

namespace Paysasa\Payments\Exceptions;

class InvalidAmountException extends PaymentException
{
    public static function hiddenPrecision(float $amount, int $exponent): self
    {
        return new self("Amount {$amount} contains more decimal places than allowed by currency exponent {$exponent}.");
    }

    public static function fractionalUnitsNotAllowed(int $minor, int $exponent): self
    {
        return new self("Amount with minor units {$minor} has fractional units which are not supported for this provider.");
    }

    public static function nonPositive(int|float|string $amount): self
    {
        return new self("Amount {$amount} must be greater than zero.");
    }
}
