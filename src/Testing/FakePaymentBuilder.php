<?php

declare(strict_types=1);

namespace Paysasa\Payments\Testing;

use Paysasa\Payments\Contracts\PaymentBuilder;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\CustomerData;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\DTOs\RefundRequest;
use Paysasa\Payments\DTOs\RefundResponse;
use Paysasa\Payments\Enums\Currency;
use Paysasa\Payments\Enums\PaymentProvider;

/**
 * Mirrors Support\FluentPaymentBuilder's chainable API exactly (same
 * method names/signatures) so test code and production code read
 * identically — only Payment::fake() changes what happens when ->charge()
 * is finally called.
 */
class FakePaymentBuilder implements PaymentBuilder
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

    public function __construct(protected PaymentFake $fake, protected string $driver)
    {
        $this->currency = Currency::from(config('paysasa.currency', 'KES'));
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

    public function split(array $splits): static
    {
        $this->splits = $splits;

        return $this;
    }

    protected function buildRequest(): ChargeRequest
    {
        return new ChargeRequest(
            provider: PaymentProvider::from($this->driver),
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
        return $this->fake->recordCharge($this->driver, $this->buildRequest());
    }

    public function authorize(): PaymentResponse
    {
        return $this->fake->recordCharge($this->driver, $this->buildRequest());
    }

    public function payout(): PaymentResponse
    {
        return $this->fake->recordCharge($this->driver, $this->buildRequest());
    }

    public function refund(string $transactionId, ?string $providerReference = null, ?string $reason = null): RefundResponse
    {
        return $this->fake->recordRefund($this->driver, new RefundRequest(
            transactionId: $transactionId,
            providerReference: $providerReference,
            amount: $this->amount ?: null,
            reason: $reason,
        ));
    }

    public function verify(string $providerReference): PaymentResponse
    {
        return PaymentResponse::makeSuccessful(PaymentProvider::from($this->driver), transactionId: null, providerReference: $providerReference);
    }
}
