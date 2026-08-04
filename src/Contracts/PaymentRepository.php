<?php

declare(strict_types=1);

namespace Paysasa\Payments\Contracts;

use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Models\Payment;

/**
 * Persistence boundary between the driver layer and Eloquent, so drivers
 * and actions never touch the Payment model directly (Repository Pattern).
 * The default binding is Support\EloquentPaymentRepository; swap it in the
 * container to persist elsewhere (e.g. a separate ledger service).
 */
interface PaymentRepository
{
    public function createFromRequest(ChargeRequest $request): Payment;

    public function recordAttempt(Payment $payment, PaymentResponse $response, ?\Throwable $exception = null): void;

    public function updateFromResponse(Payment $payment, PaymentResponse $response): Payment;

    public function findByReference(string $reference): ?Payment;

    public function findByProviderReference(string $providerReference): ?Payment;

    public function findByIdempotencyKey(string $key): ?Payment;
}
