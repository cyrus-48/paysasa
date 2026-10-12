<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\CustomerData;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Enums\Currency;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Managers\PaymentManager;

beforeEach(function () {
    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3599]),
    ]);
});

it('initiates an STK push and returns a pending response with the CheckoutRequestID', function () {
    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token']),
        'sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
            'MerchantRequestID' => '29115-34620561-1',
            'CheckoutRequestID' => 'ws_CO_191220191020363925',
            'ResponseCode' => '0',
            'ResponseDescription' => 'Success. Request accepted for processing',
            'CustomerMessage' => 'Success. Request accepted for processing',
        ]),
    ]);

    $driver = app(PaymentManager::class)->driverInstance('mpesa');

    $response = $driver->charge(new ChargeRequest(
        provider: PaymentProvider::Mpesa,
        amount: 2500,
        currency: Currency::KES,
        phone: '0712345678',
        customer: new CustomerData(name: 'Jane Doe'),
        reference: 'INV1001',
    ));

    expect($response->pending())->toBeTrue()
        ->and($response->providerReference())->toBe('ws_CO_191220191020363925');

    Http::assertSent(fn ($request) => $request->url() === 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest'
        && $request['PartyA'] === '254712345678'
        && $request['Amount'] === 2500);
});

it('returns a failed response when Daraja rejects the STK push', function () {
    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token']),
        'sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
            'requestId' => 'abc',
            'errorCode' => '400.002.02',
            'errorMessage' => 'Bad Request - Invalid PhoneNumber',
        ]),
    ]);

    $driver = app(PaymentManager::class)->driverInstance('mpesa');

    $response = $driver->charge(new ChargeRequest(
        provider: PaymentProvider::Mpesa,
        amount: 2500,
        currency: Currency::KES,
        phone: '0712345678',
    ));

    expect($response->failed())->toBeTrue()
        ->and($response->message())->toContain('Invalid PhoneNumber');
});

it('translates a successful STK callback into a successful PaymentResponse', function () {
    $driver = app(PaymentManager::class)->driverInstance('mpesa');

    $callbackBody = [
        'Body' => ['stkCallback' => [
            'MerchantRequestID' => '29115-34620561-1',
            'CheckoutRequestID' => 'ws_CO_191220191020363925',
            'ResultCode' => 0,
            'ResultDesc' => 'The service request is processed successfully.',
            'CallbackMetadata' => ['Item' => [
                ['Name' => 'Amount', 'Value' => 2500],
                ['Name' => 'MpesaReceiptNumber', 'Value' => 'NLJ7RT61SV'],
                ['Name' => 'PhoneNumber', 'Value' => 254712345678],
            ]],
        ]],
    ];

    $response = $driver->handleCallback(new WebhookPayload(PaymentProvider::Mpesa, [], json_encode($callbackBody), $callbackBody));

    expect($response->status())->toBe(PaymentStatus::Successful)
        ->and($response->amount())->toBe(2500.0)
        ->and($response->receiptNumber())->toBe('NLJ7RT61SV');
});

it('translates a customer-cancelled STK callback into a cancelled PaymentResponse', function () {
    $driver = app(PaymentManager::class)->driverInstance('mpesa');

    $callbackBody = ['Body' => ['stkCallback' => [
        'CheckoutRequestID' => 'ws_CO_1',
        'ResultCode' => 1032,
        'ResultDesc' => 'Request cancelled by user',
    ]]];

    $response = $driver->handleCallback(new WebhookPayload(PaymentProvider::Mpesa, [], json_encode($callbackBody), $callbackBody));

    expect($response->status())->toBe(PaymentStatus::Cancelled);
});

it('translates customer timeout callback into an Expired response with failure_code', function () {
    $driver = app(PaymentManager::class)->driverInstance('mpesa');

    $callbackBody = ['Body' => ['stkCallback' => [
        'CheckoutRequestID' => 'ws_CO_timeout',
        'ResultCode' => 1037,
        'ResultDesc' => 'DS timeout user cannot be reached',
    ]]];

    $response = $driver->handleCallback(new WebhookPayload(PaymentProvider::Mpesa, [], json_encode($callbackBody), $callbackBody));

    expect($response->status())->toBe(PaymentStatus::Expired)
        ->and($response->metadata['failure_code'])->toBe('timeout_expired');
});

it('returns Pending when verify catches a transaction being processed error', function () {
    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token']),
        'sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
            'ResponseCode' => '1',
            'ResponseDescription' => 'The transaction is being processed',
        ], 500),
    ]);

    $driver = app(PaymentManager::class)->driverInstance('mpesa');
    $response = $driver->verify('ws_CO_query_processing');

    expect($response->pending())->toBeTrue()
        ->and($response->message())->toContain('Transaction is being processed');
});

it('maps verify result codes correctly for cancelled and expired', function () {
    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token']),
        'sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::sequence()
            ->push(['ResultCode' => '1032', 'ResultDesc' => 'Request cancelled by user'])
            ->push(['ResultCode' => '1037', 'ResultDesc' => 'DS timeout']),
    ]);

    $driver = app(PaymentManager::class)->driverInstance('mpesa');

    $cancelled = $driver->verify('ws_CO_cancelled');
    expect($cancelled->status())->toBe(PaymentStatus::Cancelled);

    $expired = $driver->verify('ws_CO_expired');
    expect($expired->status())->toBe(PaymentStatus::Expired);
});

it('throws InvalidArgumentException when reverse is called without positive amount', function () {
    config()->set('paysasa.drivers.mpesa.initiator_name', 'test_initiator');
    config()->set('paysasa.drivers.mpesa.security_credential', 'test_cred');

    $driver = app(PaymentManager::class)->driverInstance('mpesa');

    $driver->reverse('ws_CO_ref', 0.0);
})->throws(\InvalidArgumentException::class);

it('throws InvalidAmountException when reverse is called with fractional amount', function () {
    config()->set('paysasa.drivers.mpesa.initiator_name', 'test_initiator');
    config()->set('paysasa.drivers.mpesa.security_credential', 'test_cred');

    $driver = app(PaymentManager::class)->driverInstance('mpesa');

    $driver->reverse('ws_CO_ref', 100.75);
})->throws(\Paysasa\Payments\Exceptions\InvalidAmountException::class);

it('throws UnsupportedOperationException when balance is called synchronously', function () {
    $driver = app(PaymentManager::class)->driverInstance('mpesa');

    $driver->balance();
})->throws(\Paysasa\Payments\Exceptions\UnsupportedOperationException::class);

it('rejects callback URL override when host is not in allowed_callback_hosts', function () {
    config()->set('paysasa.drivers.mpesa.allowed_callback_hosts', ['example.com', 'payments.mysite.com']);

    $driver = app(PaymentManager::class)->driverInstance('mpesa');

    $driver->charge(new ChargeRequest(
        provider: PaymentProvider::Mpesa,
        amount: 100,
        currency: Currency::KES,
        phone: '0712345678',
        callbackUrl: 'https://evil-attacker.com/webhook',
    ));
})->throws(\Paysasa\Payments\Exceptions\InvalidConfigurationException::class);

