<?php

declare(strict_types=1);

namespace Paysasa\Payments\Actions;

use Paysasa\Payments\Contracts\PaymentDriver;
use Paysasa\Payments\Contracts\PaymentRepository;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Events\PaymentCancelled;
use Paysasa\Payments\Events\PaymentExpired;
use Paysasa\Payments\Events\PaymentFailed;
use Paysasa\Payments\Events\PaymentSuccessful;
use Paysasa\Payments\Models\Payment;

/**
 * Re-queries the provider for a payment still in a non-terminal state.
 * Invoked by VerifyPaymentStatusJob on a delay after charge() returns
 * Pending, as a safety net for missed/delayed webhooks — never as the
 * primary confirmation path.
 */
class VerifyPayment
{
    public function __construct(protected PaymentRepository $repository)
    {
    }

    public function execute(PaymentDriver $driver, Payment $payment): PaymentResponse
    {
        $response = $driver->verify($payment->provider_reference ?? $payment->reference);

        if ($response->status === $payment->status) {
            return $response;
        }

        $payment = $this->repository->updateFromResponse($payment, $response);

        match ($response->status) {
            PaymentStatus::Successful => event(new PaymentSuccessful($payment, $response)),
            PaymentStatus::Failed => event(new PaymentFailed($payment, $response)),
            PaymentStatus::Cancelled => event(new PaymentCancelled($payment, $response)),
            PaymentStatus::Expired => event(new PaymentExpired($payment, $response)),
            default => null,
        };

        return $response;
    }
}
