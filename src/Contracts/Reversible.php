<?php

declare(strict_types=1);

namespace Paysasa\Payments\Contracts;

use Paysasa\Payments\DTOs\PaymentResponse;

/**
 * Distinct from Refundable: a reversal cancels a mobile-money transaction
 * outright (e.g. Daraja Transaction Reversal API) rather than crediting the
 * payer through a separate refund transaction.
 */
interface Reversible
{
    public function reverse(string $providerReference, ?float $amount = null, ?string $reason = null): PaymentResponse;
}
