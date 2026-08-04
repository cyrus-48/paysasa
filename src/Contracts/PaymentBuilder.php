<?php

declare(strict_types=1);

namespace Paysasa\Payments\Contracts;

use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\DTOs\RefundResponse;

/**
 * The fluent, chainable surface returned by PaymentManager::driver() /
 * Payment::driver(...). Both the real builder (Support\FluentPaymentBuilder)
 * and the test double (Testing\FakePaymentBuilder) implement this so
 * PaymentManager::driver() and its Testing\PaymentFake override share a
 * covariant return type — production code and test code are written
 * identically either way.
 */
interface PaymentBuilder
{
    public function amount(int|float $amount): static;

    public function currency(string $currency): static;

    public function phone(string $phone): static;

    public function cardToken(string $token): static;

    public function paymentMethod(string $paymentMethodId): static;

    public function customer(object $customer): static;

    public function reference(string $reference): static;

    public function description(string $description): static;

    public function metadata(array $metadata): static;

    public function callbackUrl(string $url): static;

    public function idempotencyKey(string $key): static;

    public function split(array $splits): static;

    public function charge(): PaymentResponse;

    public function authorize(): PaymentResponse;

    public function payout(): PaymentResponse;

    public function refund(string $transactionId, ?string $providerReference = null, ?string $reason = null): RefundResponse;

    public function verify(string $providerReference): PaymentResponse;
}
