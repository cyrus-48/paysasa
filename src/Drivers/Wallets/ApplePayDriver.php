<?php

declare(strict_types=1);

namespace Paysasa\Payments\Drivers\Wallets;

use Paysasa\Payments\Contracts\PaymentDriver;
use Paysasa\Payments\Contracts\Refundable;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\DTOs\RefundRequest;
use Paysasa\Payments\DTOs\RefundResponse;
use Paysasa\Payments\Drivers\AbstractDriver;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Exceptions\UnsupportedOperationException;
use Paysasa\Payments\Managers\PaymentManager;

/**
 * Same rationale as GooglePayDriver: Apple Pay's ApplePaySession returns a
 * PKPaymentToken that Apple Pay JS / your frontend SDK exchanges for a
 * processor token (e.g. Stripe's `payment_method` created via
 * stripe.createPaymentMethod({type: 'apple_pay', ...})) before it ever
 * reaches this backend. This driver delegates settlement to the
 * configured `gateway` driver. Domain verification
 * (`.well-known/apple-developer-merchantid-domain-association`) and
 * merchant identity certificate handling are frontend/Apple Developer
 * portal concerns, not something a backend driver performs — the
 * `merchant_certificate_path` / `domain_association_path` config keys are
 * there for you to serve that static file from your own routes.
 */
class ApplePayDriver extends AbstractDriver implements Refundable
{
    protected ?PaymentDriver $gateway = null;

    public function provider(): PaymentProvider
    {
        return PaymentProvider::ApplePay;
    }

    protected function gateway(): PaymentDriver
    {
        return $this->gateway ??= app(PaymentManager::class)->driverInstance($this->config['gateway'] ?? 'stripe');
    }

    public function charge(ChargeRequest $request): PaymentResponse
    {
        return $this->gateway()->charge($request);
    }

    public function verify(string $providerReference): PaymentResponse
    {
        return $this->gateway()->verify($providerReference);
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        $gateway = $this->gateway();

        if (! $gateway instanceof Refundable) {
            throw UnsupportedOperationException::make($this->provider()->value, 'refund');
        }

        return $gateway->refund($request);
    }
}
