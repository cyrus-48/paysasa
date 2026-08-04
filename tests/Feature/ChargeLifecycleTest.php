<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Paysasa\Payments\Facades\Payment;
use Paysasa\Payments\Models\Payment as PaymentModel;
use Paysasa\Payments\Models\PaymentAttempt;
use Paysasa\Payments\Models\Transaction;

it('persists a payment, an attempt and a transaction, and fires the lifecycle events for a pending STK push', function () {
    Event::fake([\Paysasa\Payments\Events\PaymentSuccessful::class, \Paysasa\Payments\Events\PaymentFailed::class]);

    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token']),
        'sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
            'CheckoutRequestID' => 'ws_CO_1',
            'ResponseCode' => '0',
            'CustomerMessage' => 'Success',
        ]),
    ]);

    $response = Payment::driver('mpesa')
        ->amount(2500)
        ->currency('KES')
        ->phone('0712345678')
        ->reference('INV1001')
        ->description('School Fees')
        ->metadata(['student_id' => 10])
        ->charge();

    expect($response->pending())->toBeTrue();

    $payment = PaymentModel::query()->where('reference', 'INV1001')->firstOrFail();

    expect($payment->status->value)->toBe('pending')
        ->and($payment->provider->value)->toBe('mpesa')
        ->and($payment->amount)->toBe(2500.0)
        ->and($payment->metadata)->toBe(['student_id' => 10])
        ->and(PaymentAttempt::where('payment_id', $payment->id)->count())->toBe(1)
        ->and(Transaction::where('payment_id', $payment->id)->count())->toBe(1);

    // Terminal-status events only fire once the outcome is known (webhook or verify()), never for a pending charge.
    Event::assertNotDispatched(\Paysasa\Payments\Events\PaymentSuccessful::class);
    Event::assertNotDispatched(\Paysasa\Payments\Events\PaymentFailed::class);
});

it('marks the payment successful and updates the receipt number when the STK callback lands', function () {
    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token']),
        'sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response(['CheckoutRequestID' => 'ws_CO_2', 'ResponseCode' => '0']),
    ]);

    Payment::driver('mpesa')->amount(1500)->phone('0712345678')->reference('INV2002')->charge();

    $payment = PaymentModel::query()->where('reference', 'INV2002')->firstOrFail();

    $driver = app(\Paysasa\Payments\Managers\PaymentManager::class)->driverInstance('mpesa');
    $callback = ['Body' => ['stkCallback' => [
        'CheckoutRequestID' => 'ws_CO_2',
        'ResultCode' => 0,
        'CallbackMetadata' => ['Item' => [
            ['Name' => 'Amount', 'Value' => 1500],
            ['Name' => 'MpesaReceiptNumber', 'Value' => 'ABC123XYZ'],
        ]],
    ]]];

    $response = $driver->handleCallback(new \Paysasa\Payments\DTOs\WebhookPayload(
        \Paysasa\Payments\Enums\PaymentProvider::Mpesa, [], json_encode($callback), $callback,
    ));

    app(\Paysasa\Payments\Contracts\PaymentRepository::class)->updateFromResponse($payment, $response);

    $payment->refresh();

    expect($payment->status->value)->toBe('successful')
        ->and($payment->receipt_number)->toBe('ABC123XYZ')
        ->and($payment->completed_at)->not->toBeNull();
});
