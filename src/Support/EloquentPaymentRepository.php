<?php

declare(strict_types=1);

namespace Paysasa\Payments\Support;

use Paysasa\Payments\Contracts\PaymentRepository;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Enums\TransactionType;
use Paysasa\Payments\Models\Payment;
use Paysasa\Payments\Models\PaymentAttempt;
use Paysasa\Payments\Models\Transaction;
use Ramsey\Uuid\Uuid;

/**
 * Default binding for Contracts\PaymentRepository. Swap this in the
 * container (bind PaymentRepository::class to your own implementation) if
 * you need payments persisted somewhere other than these Eloquent models —
 * the rest of the package (drivers, actions, jobs) only ever depends on
 * the contract.
 */
class EloquentPaymentRepository implements PaymentRepository
{
    public function createFromRequest(ChargeRequest $request): Payment
    {
        return Payment::create([
            'provider' => $request->provider,
            'category' => $request->provider->category(),
            'type' => TransactionType::Charge,
            'status' => PaymentStatus::Pending,
            'currency' => $request->currency->value,
            'amount' => $request->amount,
            'reference' => $request->reference ?? Uuid::uuid4()->toString(),
            'idempotency_key' => $request->idempotencyKey,
            'customer_id' => $request->customer?->id,
            'customer_name' => $request->customer?->name,
            'customer_email' => $request->customer?->email,
            'customer_phone' => $request->customer?->phone ?? $request->phone,
            'description' => $request->description,
            'metadata' => $request->metadata,
            'splits' => $request->splits ?: null,
            'callback_url' => $request->callbackUrl,
            'initiated_at' => now(),
        ]);
    }

    public function recordAttempt(Payment $payment, PaymentResponse $response, ?\Throwable $exception = null): void
    {
        $attemptNumber = $payment->attempts()->count() + 1;

        PaymentAttempt::create([
            'payment_id' => $payment->id,
            'attempt_number' => $attemptNumber,
            'status' => $response->status,
            'provider_reference' => $response->providerReference,
            'error_code' => $exception?->getCode(),
            'error_message' => $exception?->getMessage() ?? ($response->failed() ? $response->message : null),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'raw_response' => is_array($response->rawResponse) ? $response->rawResponse : null,
        ]);

        Transaction::create([
            'payment_id' => $payment->id,
            'type' => TransactionType::Charge,
            'status' => $response->status,
            'currency' => $response->currency ?? $payment->currency,
            'amount' => $response->amount ?? $payment->amount,
            'provider_reference' => $response->providerReference,
            'raw_response' => is_array($response->rawResponse) ? $response->rawResponse : null,
        ]);
    }

    public function updateFromResponse(Payment $payment, PaymentResponse $response): Payment
    {
        $payment->fill([
            'status' => $response->status,
            'provider_reference' => $response->providerReference ?? $payment->provider_reference,
            'receipt_number' => $response->receiptNumber ?? $payment->receipt_number,
            'failure_reason' => $response->failed() ? $response->message : $payment->failure_reason,
        ]);

        if ($response->status->isTerminal()) {
            $payment->completed_at ??= now();
        }

        $payment->save();

        return $payment;
    }

    public function findByReference(string $reference): ?Payment
    {
        return Payment::query()->where('reference', $reference)->first();
    }

    public function findByProviderReference(string $providerReference): ?Payment
    {
        return Payment::query()->where('provider_reference', $providerReference)->first();
    }

    public function findByIdempotencyKey(string $key): ?Payment
    {
        return Payment::query()->where('idempotency_key', $key)->first();
    }
}
