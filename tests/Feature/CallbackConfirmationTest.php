<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Paysasa\Payments\Actions\TransitionPayment;
use Paysasa\Payments\Contracts\PaymentRepository;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Enums\WebhookStatus;
use Paysasa\Payments\Events\PaymentSuccessful;
use Paysasa\Payments\Jobs\DispatchWebhookJob;
use Paysasa\Payments\Managers\PaymentManager;
use Paysasa\Payments\Models\Payment;
use Paysasa\Payments\Models\Webhook;

function confirmablePayment(string $checkoutRequestId): Payment
{
    return Payment::create([
        'provider' => PaymentProvider::Mpesa,
        'category' => PaymentProvider::Mpesa->category(),
        'reference' => 'CONF_'.$checkoutRequestId,
        'provider_reference' => $checkoutRequestId,
        'status' => PaymentStatus::Pending,
        'currency' => 'KES',
        'amount_minor' => 50000, // 500.00 KES
    ]);
}

function successCallback(string $checkoutRequestId): array
{
    return ['Body' => ['stkCallback' => [
        'CheckoutRequestID' => $checkoutRequestId,
        'ResultCode' => 0,
        'CallbackMetadata' => ['Item' => [
            ['Name' => 'Amount', 'Value' => 500],
            ['Name' => 'MpesaReceiptNumber', 'Value' => 'REC_CONF'],
        ]],
    ]]];
}

function processCallback(array $callback): Webhook
{
    $webhook = Webhook::create(['provider' => 'mpesa', 'status' => WebhookStatus::Received, 'payload' => $callback]);

    (new DispatchWebhookJob($webhook, [], (string) json_encode($callback)))->handle(
        app(PaymentManager::class),
        app(PaymentRepository::class),
        app(TransitionPayment::class),
    );

    return $webhook->refresh();
}

function fakeStkQuery(mixed $response): void
{
    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token']),
        'sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => $response,
    ]);
}

beforeEach(function () {
    // On by default in the package; the shared TestCase switches it off.
    config()->set('paysasa.drivers.mpesa.confirm_callbacks', true);
});

it('settles an STK success callback once Daraja confirms it', function () {
    Event::fake([PaymentSuccessful::class]);
    fakeStkQuery(Http::response(['ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.']));

    $payment = confirmablePayment('ws_CO_CONFIRMED');
    $webhook = processCallback(successCallback('ws_CO_CONFIRMED'));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Successful)
        ->and($payment->receipt_number)->toBe('REC_CONF')
        ->and($webhook->status)->toBe(WebhookStatus::Processed);

    Event::assertDispatched(PaymentSuccessful::class, 1);
});

it('does not settle a forged success callback when Daraja says the payment was cancelled', function () {
    Event::fake([PaymentSuccessful::class]);
    fakeStkQuery(Http::response(['ResultCode' => '1032', 'ResultDesc' => 'Request cancelled by user']));

    $payment = confirmablePayment('ws_CO_FORGED');
    $webhook = processCallback(successCallback('ws_CO_FORGED'));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Cancelled)
        ->and($payment->receipt_number)->toBeNull()
        ->and($webhook->error_message)->toContain('Callback claimed success');

    Event::assertNotDispatched(PaymentSuccessful::class);
});

it('leaves the payment pending and retries later when Daraja cannot confirm yet', function () {
    Event::fake([PaymentSuccessful::class]);
    fakeStkQuery(Http::response([
        'errorCode' => '500.001.1001',
        'errorMessage' => 'The transaction is being processed',
    ], 500));

    $payment = confirmablePayment('ws_CO_PENDING');
    $webhook = processCallback(successCallback('ws_CO_PENDING'));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($webhook->status)->not->toBe(WebhookStatus::Processed);

    Event::assertNotDispatched(PaymentSuccessful::class);
});

it('does not call Daraja when confirmation is switched off', function () {
    config()->set('paysasa.drivers.mpesa.confirm_callbacks', false);
    Http::fake();

    $payment = confirmablePayment('ws_CO_NOCONFIRM');
    processCallback(successCallback('ws_CO_NOCONFIRM'));

    Http::assertNothingSent();
    expect($payment->refresh()->status)->toBe(PaymentStatus::Successful);
});

it('does not try to confirm C2B confirmation callbacks, which carry no CheckoutRequestID', function () {
    Http::fake();

    $payment = Payment::create([
        'provider' => PaymentProvider::Mpesa,
        'category' => PaymentProvider::Mpesa->category(),
        'reference' => 'CONF_C2B',
        'provider_reference' => 'QWE123ABC',
        'status' => PaymentStatus::Pending,
        'currency' => 'KES',
        'amount_minor' => 50000,
    ]);

    processCallback(['TransID' => 'QWE123ABC', 'TransAmount' => '500.00']);

    Http::assertNothingSent();
    expect($payment->refresh()->status)->toBe(PaymentStatus::Successful);
});
