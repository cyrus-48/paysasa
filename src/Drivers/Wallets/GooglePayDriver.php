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
 * Google Pay is a wallet/tokenization UX, not an independent settlement
 * rail: the browser/Android Payment Request API hands the client an
 * encrypted payment token (via Google's push provisioning, decrypted at
 * the gateway) which is already exchanged, client-side, for a processor
 * token — e.g. a Stripe PaymentMethod id when using Stripe's Payment
 * Request Button. This driver is therefore a thin Adapter: it forwards
 * the token straight to the underlying `gateway` driver configured in
 * config('paysasa.drivers.google_pay.gateway') and returns whatever that
 * driver returns, so application code never has to know settlement
 * actually happened through Stripe/Flutterwave/etc.
 */
class GooglePayDriver extends AbstractDriver implements Refundable
{
    protected ?PaymentDriver $gateway = null;

    public function provider(): PaymentProvider
    {
        return PaymentProvider::GooglePay;
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
