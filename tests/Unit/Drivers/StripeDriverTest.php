<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\RefundRequest;
use Paysasa\Payments\Enums\Currency;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Managers\PaymentManager;

it('creates and confirms a PaymentIntent synchronously and reports success', function () {
    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_123',
            'status' => 'succeeded',
            'amount' => 250000,
            'currency' => 'kes',
        ]),
    ]);

    $driver = app(PaymentManager::class)->driverInstance('stripe');

    $response = $driver->charge(new ChargeRequest(
        provider: PaymentProvider::Stripe,
        amount: 2500,
        currency: Currency::KES,
        cardToken: 'pm_card_visa',
        reference: 'INV1001',
    ));

    expect($response->successful())->toBeTrue()
        ->and($response->providerReference())->toBe('pi_123')
        ->and($response->amount())->toBe(2500.0);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/payment_intents')
        && $request['payment_method'] === 'pm_card_visa'
        && $request['amount'] === 250000);
});

it('reports a requires_capture PaymentIntent as authorized', function () {
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_456', 'status' => 'requires_capture', 'amount' => 100000])]);

    $driver = app(PaymentManager::class)->driverInstance('stripe');

    $response = $driver->authorize(new ChargeRequest(PaymentProvider::Stripe, 1000, Currency::KES, cardToken: 'pm_card_visa'));

    expect($response->status())->toBe(PaymentStatus::Authorized);
});

it('creates a refund against a PaymentIntent', function () {
    Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded', 'amount' => 50000, 'payment_intent' => 'pi_123'])]);

    $driver = app(PaymentManager::class)->driverInstance('stripe');

    $response = $driver->refund(new RefundRequest(transactionId: 'txn-1', providerReference: 'pi_123', amount: 500));

    expect($response->successful())->toBeTrue()
        ->and($response->refundId)->toBe('re_1');
});
