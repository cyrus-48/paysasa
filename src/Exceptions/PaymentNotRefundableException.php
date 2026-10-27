<?php

declare(strict_types=1);

namespace Paysasa\Payments\Exceptions;

use Paysasa\Payments\Enums\PaymentStatus;

/**
 * Thrown when a refund is requested for a payment that cannot be refunded in
 * its current state (e.g. still pending, failed, or already fully refunded).
 */
class PaymentNotRefundableException extends PaymentException
{
    public static function forStatus(string $paymentUuid, PaymentStatus $status): self
    {
        return new self("Payment [{$paymentUuid}] cannot be refunded while its status is [{$status->value}].", [
            'payment' => $paymentUuid,
            'status' => $status->value,
        ]);
    }

    public static function notFound(string $transactionId): self
    {
        return new self("No payment found for [{$transactionId}].", [
            'transaction_id' => $transactionId,
        ]);
    }
}
