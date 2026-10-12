<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Paysasa\Payments\Facades\Payment;
use Paysasa\Payments\Models\Payment as PaymentModel;

it('handles network timeouts by leaving payment Pending with outcome_uncertain', function () {
    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token']),
        'sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => function () {
            throw new ConnectionException('Connection timed out');
        },
    ]);

    $response = Payment::driver('mpesa')
        ->amount(1000)
        ->phone('0712345678')
        ->reference('TIMEOUT_TEST')
        ->charge();

    expect($response->pending())->toBeTrue()
        ->and($response->message)->toContain('Payment outcome uncertain');

    $payment = PaymentModel::where('reference', 'TIMEOUT_TEST')->firstOrFail();

    expect($payment->status->value)->toBe('pending')
        ->and($payment->outcome_uncertain)->toBeTrue()
        ->and($payment->expires_at)->not->toBeNull();
});
