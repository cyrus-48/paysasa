<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Paysasa\Payments\Facades\Payment;
use Paysasa\Payments\Jobs\VerifyPaymentStatusJob;
use Paysasa\Payments\Models\Payment as PaymentModel;

beforeEach(function () {
    Queue::fake([VerifyPaymentStatusJob::class]);

    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token']),
        'sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response(['CheckoutRequestID' => 'ws_CO_exp', 'ResponseCode' => '0']),
    ]);
});

it('gives every new payment an expiry so abandoned checkouts are not polled forever', function () {
    Payment::driver('mpesa')->amount(500)->phone('0712345678')->reference('EXP_DEFAULT')->charge();

    $payment = PaymentModel::where('reference', 'EXP_DEFAULT')->firstOrFail();

    // M-Pesa defaults to a 15 minute window.
    expect($payment->expires_at)->not->toBeNull()
        ->and($payment->expires_at->between(now()->addMinutes(14), now()->addMinutes(16)))->toBeTrue();
});

it('honours a per-provider pending_ttl_minutes override', function () {
    config()->set('paysasa.drivers.mpesa.pending_ttl_minutes', 5);

    Payment::driver('mpesa')->amount(500)->phone('0712345678')->reference('EXP_OVERRIDE')->charge();

    $payment = PaymentModel::where('reference', 'EXP_OVERRIDE')->firstOrFail();

    expect($payment->expires_at->between(now()->addMinutes(4), now()->addMinutes(6)))->toBeTrue();
});

it('falls back to the global pending_ttl_minutes for providers without an override', function () {
    config()->set('paysasa.drivers.mpesa.pending_ttl_minutes', null);
    config()->set('paysasa.pending_ttl_minutes', 90);

    Payment::driver('mpesa')->amount(500)->phone('0712345678')->reference('EXP_GLOBAL')->charge();

    $payment = PaymentModel::where('reference', 'EXP_GLOBAL')->firstOrFail();

    expect($payment->expires_at->between(now()->addMinutes(89), now()->addMinutes(91)))->toBeTrue();
});
