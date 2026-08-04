<?php

declare(strict_types=1);

namespace Paysasa\Payments\DTOs;

use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;

/**
 * The single response shape returned by every driver for every operation
 * (charge, verify, refund, reverse...) regardless of provider. Application
 * code should never need to branch on provider() to interpret a response —
 * that defeats the purpose of the abstraction.
 *
 * Build instances via the make*() named constructors (makeSuccessful(),
 * makeFailed(), makePending(), makeCancelled()) rather than `new
 * PaymentResponse(...)` directly — they fill in the right PaymentStatus so
 * callers can't accidentally construct e.g. a "successful" response with
 * PaymentStatus::Failed.
 */
final class PaymentResponse
{
    public function __construct(
        public readonly PaymentProvider $provider,
        public readonly PaymentStatus $status,
        public readonly ?string $transactionId = null,     // our internal UUID
        public readonly ?string $providerReference = null, // e.g. MpesaReceiptNumber, Stripe PaymentIntent id
        public readonly ?float $amount = null,
        public readonly ?string $currency = null,
        public readonly ?string $message = null,
        public readonly ?string $receiptNumber = null,
        public readonly mixed $rawResponse = null,
        public readonly array $metadata = [],
    ) {
    }

    public static function makeSuccessful(
        PaymentProvider $provider,
        ?string $transactionId,
        ?string $providerReference,
        ?float $amount = null,
        ?string $currency = null,
        ?string $message = null,
        ?string $receiptNumber = null,
        mixed $rawResponse = null,
        array $metadata = [],
    ): self {
        return new self($provider, PaymentStatus::Successful, $transactionId, $providerReference, $amount, $currency, $message, $receiptNumber, $rawResponse, $metadata);
    }

    public static function makePending(
        PaymentProvider $provider,
        ?string $transactionId,
        ?string $providerReference = null,
        ?string $message = null,
        mixed $rawResponse = null,
        array $metadata = [],
    ): self {
        return new self($provider, PaymentStatus::Pending, $transactionId, $providerReference, null, null, $message, null, $rawResponse, $metadata);
    }

    public static function makeFailed(
        PaymentProvider $provider,
        ?string $transactionId = null,
        ?string $message = null,
        mixed $rawResponse = null,
        array $metadata = [],
    ): self {
        return new self($provider, PaymentStatus::Failed, $transactionId, null, null, null, $message, null, $rawResponse, $metadata);
    }

    public static function makeCancelled(
        PaymentProvider $provider,
        ?string $transactionId = null,
        ?string $message = null,
        mixed $rawResponse = null,
    ): self {
        return new self($provider, PaymentStatus::Cancelled, $transactionId, null, null, null, $message, null, $rawResponse);
    }

    public function successful(): bool
    {
        return $this->status->isSuccessful();
    }

    public function failed(): bool
    {
        return $this->status === PaymentStatus::Failed;
    }

    public function pending(): bool
    {
        return in_array($this->status, [PaymentStatus::Pending, PaymentStatus::Processing, PaymentStatus::Authorized], true);
    }

    public function cancelled(): bool
    {
        return $this->status === PaymentStatus::Cancelled;
    }

    public function transactionId(): ?string
    {
        return $this->transactionId;
    }

    public function providerReference(): ?string
    {
        return $this->providerReference;
    }

    public function provider(): PaymentProvider
    {
        return $this->provider;
    }

    public function amount(): ?float
    {
        return $this->amount;
    }

    public function currency(): ?string
    {
        return $this->currency;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function status(): PaymentStatus
    {
        return $this->status;
    }

    public function receiptNumber(): ?string
    {
        return $this->receiptNumber;
    }

    public function rawResponse(): mixed
    {
        return $this->rawResponse;
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider->value,
            'status' => $this->status->value,
            'transaction_id' => $this->transactionId,
            'provider_reference' => $this->providerReference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'message' => $this->message,
            'receipt_number' => $this->receiptNumber,
            'metadata' => $this->metadata,
        ];
    }
}
