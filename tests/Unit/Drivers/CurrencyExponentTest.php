<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Paysasa\Payments\DTOs\RefundRequest;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Managers\PaymentManager;

// RWF is a zero-decimal currency: 5000 minor units == 5000 RWF, not 50.00.

it('reads Stripe amounts using the currency exponent', function (string $currency, int $minor, float $expected) {
    Http::fake(['api.stripe.com/v1/payment_intents/*' => Http::response([
        'id' => 'pi_x', 'status' => 'succeeded', 'amount' => $minor, 'currency' => $currency,
    ])]);

    $response = app(PaymentManager::class)->driverInstance('stripe')->verify('pi_x');

    expect($response->amount)->toBe($expected);
})->with([
    'USD two-decimal' => ['usd', 2500, 25.0],
    'KES two-decimal' => ['kes', 250000, 2500.0],
    'RWF zero-decimal' => ['rwf', 5000, 5000.0],
]);

it('sends Stripe refund amounts in the currency minor unit', function (string $currency, float $amount, int $expectedMinor) {
    Http::fake(['api.stripe.com/v1/refunds' => Http::response([
        'id' => 're_1', 'status' => 'succeeded', 'amount' => $expectedMinor, 'currency' => strtolower($currency), 'payment_intent' => 'pi_1',
    ])]);

    $response = app(PaymentManager::class)->driverInstance('stripe')
        ->refund(new RefundRequest('txn-1', 'pi_1', $amount, currency: $currency));

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/refunds') && (int) $request['amount'] === $expectedMinor);
    expect($response->amount)->toBe($amount);
})->with([
    'USD' => ['USD', 25.0, 2500],
    'RWF' => ['RWF', 5000.0, 5000],
]);

it('captures a partial Stripe amount using the intent currency', function () {
    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_cap' => Http::response(['id' => 'pi_cap', 'status' => 'requires_capture', 'amount' => 9000, 'currency' => 'rwf']),
        'api.stripe.com/v1/payment_intents/pi_cap/capture' => Http::response(['id' => 'pi_cap', 'status' => 'succeeded', 'amount' => 5000, 'currency' => 'rwf']),
    ]);

    app(PaymentManager::class)->driverInstance('stripe')->capture('pi_cap', 5000.0);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/capture') && (int) $request['amount_to_capture'] === 5000);
});

it('reads Paystack amounts using the currency exponent', function (string $currency, int $minor, float $expected) {
    config()->set('paysasa.drivers.paystack.secret_key', 'sk_test_x');
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => [
        'status' => 'success', 'reference' => 'ref1', 'amount' => $minor, 'currency' => $currency,
    ]])]);

    $payload = new WebhookPayload(PaymentProvider::Paystack, [], '{}', ['data' => ['reference' => 'ref1']]);
    $response = app(PaymentManager::class)->driverInstance('paystack')->handleCallback($payload);

    expect($response->amount)->toBe($expected);
})->with([
    'NGN two-decimal' => ['NGN', 250000, 2500.0],
    'KES two-decimal' => ['KES', 250000, 2500.0],
    'RWF zero-decimal' => ['RWF', 5000, 5000.0],
]);

it('sends Paystack refund amounts in the currency minor unit', function (string $currency, float $amount, int $expectedMinor) {
    config()->set('paysasa.drivers.paystack.secret_key', 'sk_test_x');
    Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 9, 'status' => 'processed']])]);

    app(PaymentManager::class)->driverInstance('paystack')
        ->refund(new RefundRequest('txn-1', 'ref1', $amount, currency: $currency));

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/refund') && (int) $request['amount'] === $expectedMinor);
})->with([
    'KES' => ['KES', 25.0, 2500],
    'RWF' => ['RWF', 5000.0, 5000],
]);
