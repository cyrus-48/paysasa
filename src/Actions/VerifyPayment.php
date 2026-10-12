<?php

declare(strict_types=1);

namespace Paysasa\Payments\Actions;

use Paysasa\Payments\Contracts\PaymentDriver;
use Paysasa\Payments\Contracts\PaymentRepository;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Models\Payment;

/**
 * Re-queries the provider for a payment still in a non-terminal state.
 * Invoked by VerifyPaymentStatusJob on a delay after charge() returns
 * Pending, as a safety net for missed/delayed webhooks — never as the
 * primary confirmation path.
 */
class VerifyPayment
{
    public function __construct(
        protected PaymentRepository $repository,
        protected TransitionPayment $transition,
    ) {
    }

    public function execute(PaymentDriver $driver, Payment $payment): PaymentResponse
    {
        $response = $driver->verify($payment->provider_reference ?? $payment->reference);

        ($this->transition)($payment, $response, 'verify');

        return $response;
    }
}
