<?php

declare(strict_types=1);

namespace Paysasa\Payments\Support;

use Paysasa\Payments\Actions\InitiatePayment;
use Paysasa\Payments\Actions\ProcessRefund;
use Paysasa\Payments\Contracts\Authorizable;
use Paysasa\Payments\Contracts\BalanceInquirable;
use Paysasa\Payments\Contracts\PaymentBuilder;
use Paysasa\Payments\Contracts\PaymentDriver;
use Paysasa\Payments\Contracts\PayoutCapable;
use Paysasa\Payments\Contracts\Refundable;
use Paysasa\Payments\Contracts\Reversible;
use Paysasa\Payments\DTOs\BalanceResponse;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\CustomerData;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\DTOs\RefundRequest;
use Paysasa\Payments\DTOs\RefundResponse;
use Paysasa\Payments\Enums\Currency;
use Paysasa\Payments\Exceptions\UnsupportedOperationException;

/**
 * The fluent, Query-Builder-style object returned by
 * PaymentManager::driver(). Accumulates a ChargeRequest across chained
 * calls, then hands it to the configured driver via InitiatePayment on
 * ->charge(). Immutability of the underlying DTO is preserved internally;
 * this builder is the one mutable staging object by design, mirroring how
 * Illuminate\Database\Query\Builder works.
 */
class FluentPaymentBuilder implements PaymentBuilder
{
    protected float $amount = 0.0;

    protected Currency $currency;

    protected ?string $phone = null;

    protected ?string $cardToken = null;

    protected ?string $paymentMethodId = null;

    protected ?CustomerData $customer = null;

    protected ?string $reference = null;

    protected ?string $description = null;

    protected array $metadata = [];

    protected ?string $callbackUrl = null;

    protected ?string $idempotencyKey = null;

    protected array $splits = [];

    public function __construct(
        protected PaymentDriver $driver,
        protected InitiatePayment $initiatePayment,
        protected ProcessRefund $processRefund,
        string $defaultCurrency = 'KES',
    ) {
        $this->currency = Currency::from($defaultCurrency);
    }

    public function amount(int|float $amount): static
    {
        $this->amount = (float) $amount;

        return $this;
    }

    public function currency(string $currency): static
    {
        $this->currency = Currency::from(strtoupper($currency));

        return $this;
    }

    public function phone(string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function cardToken(string $token): static
    {
        $this->cardToken = $token;

        return $this;
    }

    /** Charge a previously saved Models\PaymentMethod by its token/reference. */
    public function paymentMethod(string $paymentMethodId): static
    {
        $this->paymentMethodId = $paymentMethodId;

        return $this;
    }

    public function customer(object $customer): static
    {
        $this->customer = $customer instanceof CustomerData ? $customer : CustomerData::fromModel($customer);

        return $this;
    }

    public function reference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function metadata(array $metadata): static
    {
        $this->metadata = array_merge($this->metadata, $metadata);

        return $this;
    }

    public function callbackUrl(string $url): static
    {
        $this->callbackUrl = $url;

        return $this;
    }

    public function idempotencyKey(string $key): static
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    /** @param array<int, array{recipient: string, amount: float}> $splits */
    public function split(array $splits): static
    {
        $this->splits = $splits;

        return $this;
    }

    protected function buildRequest(): ChargeRequest
    {
        return new ChargeRequest(
            provider: $this->driver->provider(),
            amount: $this->amount,
            currency: $this->currency,
            phone: $this->phone,
            cardToken: $this->cardToken,
            customer: $this->customer,
            reference: $this->reference,
            description: $this->description,
            metadata: $this->metadata,
            callbackUrl: $this->callbackUrl,
            idempotencyKey: $this->idempotencyKey,
            paymentMethodId: $this->paymentMethodId,
            splits: $this->splits,
        );
    }

    public function charge(): PaymentResponse
    {
        return $this->initiatePayment->execute($this->driver, $this->buildRequest());
    }

    public function authorize(): PaymentResponse
    {
        if (! $this->driver instanceof Authorizable) {
            throw UnsupportedOperationException::make($this->driver->provider()->value, 'authorize');
        }

        return $this->driver->authorize($this->buildRequest());
    }

    public function payout(): PaymentResponse
    {
        if (! $this->driver instanceof PayoutCapable) {
            throw UnsupportedOperationException::make($this->driver->provider()->value, 'payout');
        }

        return $this->driver->payout($this->buildRequest());
    }

    public function refund(string $transactionId, ?string $providerReference = null, ?string $reason = null): RefundResponse
    {
        if (! $this->driver instanceof Refundable) {
            throw UnsupportedOperationException::make($this->driver->provider()->value, 'refund');
        }

        $payment = app(\Paysasa\Payments\Contracts\PaymentRepository::class)->findByReference($transactionId)
            ?? app(\Paysasa\Payments\Contracts\PaymentRepository::class)->findByProviderReference($providerReference ?? $transactionId);

        $request = new RefundRequest(
            transactionId: $transactionId,
            providerReference: $providerReference ?? $payment?->provider_reference,
            amount: $this->amount ?: null,
            reason: $reason,
        );

        return $this->processRefund->execute($this->driver, $payment, $request);
    }

    public function reverse(string $providerReference, ?string $reason = null): PaymentResponse
    {
        if (! $this->driver instanceof Reversible) {
            throw UnsupportedOperationException::make($this->driver->provider()->value, 'reverse');
        }

        return $this->driver->reverse($providerReference, $this->amount ?: null, $reason);
    }

    public function verify(string $providerReference): PaymentResponse
    {
        return $this->driver->verify($providerReference);
    }

    public function balance(): BalanceResponse
    {
        if (! $this->driver instanceof BalanceInquirable) {
            throw UnsupportedOperationException::make($this->driver->provider()->value, 'balance');
        }

        return $this->driver->balance();
    }
}
