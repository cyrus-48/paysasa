<?php

declare(strict_types=1);

namespace Paysasa\Payments\Testing;

use Paysasa\Payments\Contracts\Authorizable;
use Paysasa\Payments\Contracts\BalanceInquirable;
use Paysasa\Payments\Contracts\PayoutCapable;
use Paysasa\Payments\Contracts\Refundable;
use Paysasa\Payments\Contracts\Reversible;
use Paysasa\Payments\Contracts\WebhookHandler;
use Paysasa\Payments\DTOs\BalanceResponse;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\DTOs\RefundRequest;
use Paysasa\Payments\DTOs\RefundResponse;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Drivers\AbstractDriver;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\RefundStatus;

/**
 * A real PaymentDriver implementation (every capability interface) that
 * makes no HTTP calls — for unit-testing your OWN code that type-hints
 * Contracts\PaymentDriver directly (rather than going through the fluent
 * API, where Payment::fake() is the simpler choice). Bind it in the
 * container: $this->app->instance(PaymentDriver::class, new FakeDriver());
 */
class FakeDriver extends AbstractDriver implements Authorizable, BalanceInquirable, PayoutCapable, Refundable, Reversible, WebhookHandler
{
    public function __construct(protected PaymentProvider $fakeProvider = PaymentProvider::Mpesa)
    {
        parent::__construct([], app(\Paysasa\Payments\Support\PaymentLogger::class));
    }

    public function provider(): PaymentProvider
    {
        return $this->fakeProvider;
    }

    public function charge(ChargeRequest $request): PaymentResponse
    {
        return PaymentResponse::makeSuccessful($this->provider(), transactionId: null, providerReference: 'FAKE-REF', amount: $request->amount, currency: $request->currency->value);
    }

    public function authorize(ChargeRequest $request): PaymentResponse
    {
        return $this->charge($request);
    }

    public function capture(string $providerReference, ?float $amount = null): PaymentResponse
    {
        return PaymentResponse::makeSuccessful($this->provider(), transactionId: null, providerReference: $providerReference, amount: $amount);
    }

    public function void(string $providerReference): PaymentResponse
    {
        return PaymentResponse::makeCancelled($this->provider(), message: 'Voided');
    }

    public function verify(string $providerReference): PaymentResponse
    {
        return PaymentResponse::makeSuccessful($this->provider(), transactionId: null, providerReference: $providerReference);
    }

    public function payout(ChargeRequest $request): PaymentResponse
    {
        return $this->charge($request);
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        return new RefundResponse($this->provider(), RefundStatus::Completed, amount: $request->amount);
    }

    public function reverse(string $providerReference, ?float $amount = null, ?string $reason = null): PaymentResponse
    {
        return PaymentResponse::makeSuccessful($this->provider(), transactionId: null, providerReference: $providerReference, amount: $amount);
    }

    public function balance(): BalanceResponse
    {
        return new BalanceResponse($this->provider(), available: 100000.0);
    }

    public function handleCallback(WebhookPayload $payload): PaymentResponse
    {
        return PaymentResponse::makeSuccessful($this->provider(), transactionId: null, providerReference: $payload->get('reference'));
    }
}
