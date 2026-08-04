<?php

declare(strict_types=1);

namespace Paysasa\Payments\Contracts;

use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Enums\PaymentProvider;

/**
 * The minimum contract every driver must satisfy. Deliberately small
 * (Interface Segregation Principle) — capabilities that not every provider
 * supports (refunds, B2C payouts, balance inquiry...) live in their own
 * interfaces below so a driver only implements what its provider actually
 * offers, and callers can `instanceof` check before invoking an optional
 * capability instead of catching UnsupportedOperationException everywhere.
 */
interface PaymentDriver
{
    public function provider(): PaymentProvider;

    /**
     * Initiate a payment collection. For mobile money this triggers an STK
     * push / USSD prompt; for cards this creates a PaymentIntent/charge;
     * for banking rails it initiates a transfer request. May return a
     * Pending status when the provider confirms asynchronously via webhook.
     */
    public function charge(ChargeRequest $request): PaymentResponse;

    /**
     * Re-query the provider for the current status of a previously
     * initiated transaction — used by VerifyPaymentStatusJob when a
     * webhook hasn't arrived within the expected window.
     */
    public function verify(string $providerReference): PaymentResponse;
}
