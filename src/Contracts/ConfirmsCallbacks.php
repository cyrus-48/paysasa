<?php

declare(strict_types=1);

namespace Paysasa\Payments\Contracts;

use Paysasa\Payments\DTOs\PaymentResponse;

/**
 * Implemented by drivers whose inbound callbacks carry no signature (e.g.
 * Daraja STK callbacks). For such a callback that claims success,
 * DispatchWebhookJob asks the provider directly via PaymentDriver::verify()
 * before settling the payment, so a forged or replayed callback can never
 * mark an unpaid payment as paid on its own.
 */
interface ConfirmsCallbacks
{
    /** Whether this (already-parsed) callback must be confirmed with the provider before it is trusted. */
    public function shouldConfirmCallback(PaymentResponse $callback): bool;
}
