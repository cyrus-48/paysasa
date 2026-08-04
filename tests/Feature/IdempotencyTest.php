<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Paysasa\Payments\Exceptions\IdempotencyConflictException;
use Paysasa\Payments\Facades\Payment;

beforeEach(function () {
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
