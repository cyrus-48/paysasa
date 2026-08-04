<?php

declare(strict_types=1);

namespace Paysasa\Payments\DTOs;

use Paysasa\Payments\Enums\Currency;
use Paysasa\Payments\Enums\PaymentProvider;
use Ramsey\Uuid\Uuid;

/**
 * The immutable payload assembled by FluentPaymentBuilder and handed to a
 * driver's charge()/authorize() method. Every driver receives the exact
 * same shape regardless of provider — provider-specific translation
 * happens inside the driver, not in this DTO.
 */
final class ChargeRequest
{
    public function __construct(
        public readonly PaymentProvider $provider,
        public readonly float $amount,
        public readonly Currency $currency,
        public readonly ?string $phone = null,
        public readonly ?string $cardToken = null,
        public readonly ?CustomerData $customer = null,
        public readonly ?string $reference = null,
        public readonly ?string $description = null,
        public readonly array $metadata = [],
        public readonly ?string $callbackUrl = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $paymentMethodId = null, // saved payment method
        public readonly array $splits = [], // marketplace / split-payment recipients
    ) {
    }

    public function idempotencyKeyOrGenerated(): string
    {
        return $this->idempotencyKey ?? Uuid::uuid4()->toString();
    }

    public function withIdempotencyKey(string $key): self
    {
        return new self(
            provider: $this->provider,
            amount: $this->amount,
            currency: $this->currency,
            phone: $this->phone,
            cardToken: $this->cardToken,
            customer: $this->customer,
            reference: $this->reference,
            description: $this->description,
            metadata: $this->metadata,
            callbackUrl: $this->callbackUrl,
            idempotencyKey: $key,
            paymentMethodId: $this->paymentMethodId,
            splits: $this->splits,
        );
    }

    /** Fingerprint used to detect idempotency-key reuse against a different payload. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->provider->value, $this->amount, $this->currency->value,
            $this->phone, $this->reference, $this->paymentMethodId,
        ]));
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider->value,
            'amount' => $this->amount,
            'currency' => $this->currency->value,
            'phone' => $this->phone,
            'customer' => $this->customer?->toArray(),
            'reference' => $this->reference,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'callback_url' => $this->callbackUrl,
            'idempotency_key' => $this->idempotencyKey,
            'payment_method_id' => $this->paymentMethodId,
            'splits' => $this->splits,
        ];
    }
}
