<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\Enums\Currency;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Exceptions\IdempotencyConflictException;
use Paysasa\Payments\Facades\Payment;
use Paysasa\Payments\Jobs\VerifyPaymentStatusJob;
use Paysasa\Payments\Support\IdempotencyManager;

beforeEach(function () {
    Queue::fake([VerifyPaymentStatusJob::class]);

    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token']),
        'sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response(['CheckoutRequestID' => 'ws_CO_dup', 'ResponseCode' => '0']),
    ]);
});

it('returns the cached response instead of re-calling the provider for a repeated idempotency key', function () {
    $key = 'idem-key-1';

    $first = Payment::driver('mpesa')->amount(500)->phone('0700000000')->reference('INV-A')->idempotencyKey($key)->charge();
    $second = Payment::driver('mpesa')->amount(500)->phone('0700000000')->reference('INV-A')->idempotencyKey($key)->charge();

    expect($second->providerReference())->toBe($first->providerReference());

    Http::assertSentCount(2); // 1 oauth token + 1 stk push -- the second charge() call never re-hits the network
});

it('throws when the same idempotency key is reused with a different payload', function () {
    $key = 'idem-key-2';

    Payment::driver('mpesa')->amount(500)->phone('0700000000')->reference('INV-B')->idempotencyKey($key)->charge();

    Payment::driver('mpesa')->amount(999)->phone('0700000000')->reference('INV-B')->idempotencyKey($key)->charge();
})->throws(IdempotencyConflictException::class);

it('propagates an exception from the charge callback and runs it exactly once', function () {
    $request = new ChargeRequest(
        provider: PaymentProvider::Mpesa,
        amount: 500,
        currency: Currency::KES,
        phone: '0700000000',
        reference: 'INV-THROW',
        idempotencyKey: 'idem-key-throws',
    );

    $calls = 0;

    // A callback that fails must surface its own error. Re-running it would
    // hide the failure (and could double-charge), so it must be invoked once.
    expect(fn () => app(IdempotencyManager::class)->remember($request, function () use (&$calls) {
        $calls++;

        throw new RuntimeException('provider exploded');
    }))->toThrow(RuntimeException::class, 'provider exploded');

    expect($calls)->toBe(1);
});

it('releases the lock after the callback throws so a retry can proceed', function () {
    $request = new ChargeRequest(
        provider: PaymentProvider::Mpesa,
        amount: 500,
        currency: Currency::KES,
        phone: '0700000000',
        reference: 'INV-RETRY',
        idempotencyKey: 'idem-key-retry',
    );

    $manager = app(IdempotencyManager::class);

    try {
        $manager->remember($request, fn () => throw new RuntimeException('first attempt fails'));
    } catch (RuntimeException) {
        // expected
    }

    $second = $manager->remember($request, fn () => \Paysasa\Payments\DTOs\PaymentResponse::makePending(PaymentProvider::Mpesa, null, 'ws_CO_retry'));

    expect($second->providerReference())->toBe('ws_CO_retry');
});
