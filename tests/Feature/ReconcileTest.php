<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Paysasa\Payments\Actions\TransitionPayment;
use Paysasa\Payments\Actions\VerifyPayment;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Events\PaymentExpired;
use Paysasa\Payments\Events\PaymentSuccessful;
use Paysasa\Payments\Jobs\VerifyPaymentStatusJob;
use Paysasa\Payments\Managers\PaymentManager;
use Paysasa\Payments\Models\Payment;

function pendingMpesaPayment(string $reference, array $overrides = []): Payment
{
    return Payment::create(array_merge([
        'provider' => PaymentProvider::Mpesa,
        'category' => PaymentProvider::Mpesa->category(),
        'reference' => $reference,
        'status' => PaymentStatus::Pending,
        'amount_minor' => 1000,
    ], $overrides));
}

function runVerifyJob(Payment $payment): void
{
    (new VerifyPaymentStatusJob($payment, 'mpesa'))->handle(
        app(PaymentManager::class),
        app(VerifyPayment::class),
        app(TransitionPayment::class),
    );
}

function fakeDarajaStkQuery(mixed $stkQueryResponse): void
{
    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'fake-token']),
        'sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => $stkQueryResponse,
    ]);
}

it('sweeps pending and processing payments and dispatches VerifyPaymentStatusJob', function () {
    Queue::fake([VerifyPaymentStatusJob::class]);

    $p1 = pendingMpesaPayment('REC_SWEEP_1', ['last_checked_at' => null]);
    $p2 = pendingMpesaPayment('REC_SWEEP_2', ['status' => PaymentStatus::Successful]);

    $this->artisan('paysasa:reconcile')->assertSuccessful();

    Queue::assertPushed(VerifyPaymentStatusJob::class, fn ($job) => $job->payment->id === $p1->id);
    Queue::assertNotPushed(VerifyPaymentStatusJob::class, fn ($job) => $job->payment->id === $p2->id);
});

it('backs off the sweep for payments that keep coming back unresolved', function () {
    Queue::fake([VerifyPaymentStatusJob::class]);

    $fresh = pendingMpesaPayment('REC_BACKOFF_FRESH', ['check_count' => 1, 'last_checked_at' => now()->subMinutes(2)]);
    $recentlyChecked = pendingMpesaPayment('REC_BACKOFF_RECENT', ['check_count' => 20, 'last_checked_at' => now()->subMinutes(5)]);
    $stale = pendingMpesaPayment('REC_BACKOFF_STALE', ['check_count' => 20, 'last_checked_at' => now()->subMinutes(20)]);

    $this->artisan('paysasa:reconcile')->assertSuccessful();

    Queue::assertPushed(VerifyPaymentStatusJob::class, fn ($job) => $job->payment->id === $fresh->id);
    Queue::assertPushed(VerifyPaymentStatusJob::class, fn ($job) => $job->payment->id === $stale->id);
    Queue::assertNotPushed(VerifyPaymentStatusJob::class, fn ($job) => $job->payment->id === $recentlyChecked->id);
});

it('expires a payment past expires_at when the provider still reports no outcome', function () {
    Event::fake([PaymentExpired::class, PaymentSuccessful::class]);
    fakeDarajaStkQuery(Http::response(['ResponseCode' => '0', 'ResponseDescription' => 'Success'])); // no ResultCode => still pending

    $payment = pendingMpesaPayment('REC_EXPIRE_1', ['expires_at' => now()->subMinutes(5)]);

    runVerifyJob($payment);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Expired);

    Event::assertDispatched(PaymentExpired::class, 1);
    Event::assertNotDispatched(PaymentSuccessful::class);
});

it('settles instead of expiring a payment the provider confirms as paid after its window closed', function () {
    Event::fake([PaymentExpired::class, PaymentSuccessful::class]);
    fakeDarajaStkQuery(Http::response(['ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.']));

    $payment = pendingMpesaPayment('REC_EXPIRE_PAID', ['expires_at' => now()->subMinutes(5)]);

    runVerifyJob($payment);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Successful);

    Event::assertDispatched(PaymentSuccessful::class, 1);
    Event::assertNotDispatched(PaymentExpired::class);
});

it('does not expire a payment before its window closes even when the provider has no answer yet', function () {
    Event::fake([PaymentExpired::class]);
    fakeDarajaStkQuery(Http::response(['ResponseCode' => '0']));

    $payment = pendingMpesaPayment('REC_NOT_YET', ['expires_at' => now()->addMinutes(10)]);

    runVerifyJob($payment);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->check_count)->toBe(1);

    Event::assertNotDispatched(PaymentExpired::class);
});

it('expires a payment past its window when the provider cannot be reached', function () {
    Event::fake([PaymentExpired::class]);
    fakeDarajaStkQuery(Http::response(['errorMessage' => 'Internal Server Error'], 500));

    $payment = pendingMpesaPayment('REC_EXPIRE_5XX', ['expires_at' => now()->subMinutes(5)]);

    runVerifyJob($payment);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Expired);

    Event::assertDispatched(PaymentExpired::class, 1);
});

it('survives a network error while verifying and leaves an in-window payment pending', function () {
    fakeDarajaStkQuery(function () {
        throw new ConnectionException('Connection timed out');
    });

    $payment = pendingMpesaPayment('REC_NETWORK_ERR', ['expires_at' => now()->addMinutes(10)]);

    runVerifyJob($payment);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});
