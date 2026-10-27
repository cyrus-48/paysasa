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

    public static function exceedsRefundable(int $requestedMinor, int $refundableMinor): self
    {
        return new self("Refund of {$requestedMinor} minor units exceeds the refundable balance of {$refundableMinor}.", [
            'requested_minor' => $requestedMinor,
            'refundable_minor' => $refundableMinor,
        ]);
    }

    public static function nonPositive(int|float|string $amount): self
    {
        return new self("Amount {$amount} must be greater than zero.");
    }
}
