<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Paysasa\Payments\Actions\TransitionPayment;
use Paysasa\Payments\Contracts\PaymentRepository;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Events\PaymentExpired;
use Paysasa\Payments\Events\PaymentFailed;
use Paysasa\Payments\Events\PaymentRefunded;
use Paysasa\Payments\Events\PaymentReversed;
use Paysasa\Payments\Events\PaymentStateConflict;
use Paysasa\Payments\Events\PaymentSuccessful;
use Paysasa\Payments\Models\Payment;

it('transitions payment from Pending to Successful and fires PaymentSuccessful once', function () {
    Event::fake([PaymentSuccessful::class]);

    $payment = Payment::create([
        'provider' => PaymentProvider::Mpesa,
        'category' => PaymentProvider::Mpesa->category(),
        'reference' => 'TX_TRANS_1',
        'status' => PaymentStatus::Pending,
        'amount_minor' => 10000,
    ]);

    $transition = app(TransitionPayment::class);
    $response = PaymentResponse::makeSuccessful(PaymentProvider::Mpesa, (string) $payment->uuid, 'PR_1', 100.0, 'KES');

    $result = $transition($payment, $response, 'test');

    expect($result->changed)->toBeTrue()
        ->and($result->conflict)->toBeFalse();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Successful);

    Event::assertDispatched(PaymentSuccessful::class, 1);
});

it('rejects an illegal transition from Successful to Failed and fires PaymentStateConflict', function () {
    Event::fake([PaymentStateConflict::class, PaymentFailed::class]);

    $payment = Payment::create([
        'provider' => PaymentProvider::Mpesa,
        'category' => PaymentProvider::Mpesa->category(),
        'reference' => 'TX_TRANS_2',
        'status' => PaymentStatus::Successful,
        'amount_minor' => 10000,
    ]);

    $transition = app(TransitionPayment::class);
    $response = PaymentResponse::makeFailed(PaymentProvider::Mpesa, (string) $payment->uuid, 'Failed late');

    $result = $transition($payment, $response, 'webhook');

    expect($result->changed)->toBeFalse()
        ->and($result->conflict)->toBeTrue();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Successful);

    Event::assertDispatched(PaymentStateConflict::class, 1);
    Event::assertNotDispatched(PaymentFailed::class);
});

it('allows late confirmation transition from Expired to Successful with late_confirmation flag', function () {
    Event::fake([PaymentSuccessful::class]);

    $payment = Payment::create([
        'provider' => PaymentProvider::Mpesa,
        'category' => PaymentProvider::Mpesa->category(),
        'reference' => 'TX_TRANS_3',
        'status' => PaymentStatus::Expired,
        'amount_minor' => 10000,
    ]);

    $transition = app(TransitionPayment::class);
    $response = PaymentResponse::makeSuccessful(PaymentProvider::Mpesa, (string) $payment->uuid, 'PR_3', 100.0, 'KES');

    $result = $transition($payment, $response, 'webhook');

    expect($result->changed)->toBeTrue()
        ->and($result->conflict)->toBeFalse();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Successful)
        ->and($payment->flags['late_confirmation'] ?? false)->toBeTrue();

    Event::assertDispatched(PaymentSuccessful::class, 1);
});

it('is idempotent for identical status transitions and fires no duplicate events', function () {
    Event::fake([PaymentSuccessful::class]);

    $payment = Payment::create([
        'provider' => PaymentProvider::Mpesa,
        'category' => PaymentProvider::Mpesa->category(),
        'reference' => 'TX_TRANS_4',
        'status' => PaymentStatus::Successful,
        'amount_minor' => 10000,
    ]);

    $transition = app(TransitionPayment::class);
    $response = PaymentResponse::makeSuccessful(PaymentProvider::Mpesa, (string) $payment->uuid, 'PR_4', 100.0, 'KES');

    $result = $transition($payment, $response, 'webhook');

    expect($result->changed)->toBeFalse()
        ->and($result->conflict)->toBeFalse();

    Event::assertNotDispatched(PaymentSuccessful::class);
});

it('fires PaymentRefunded with a RefundResponse when a payment transitions to Refunded', function () {
    Event::fake([PaymentRefunded::class]);

    $payment = Payment::create([
        'provider' => PaymentProvider::Stripe,
        'category' => PaymentProvider::Stripe->category(),
        'reference' => 'TX_TRANS_REFUND',
        'status' => PaymentStatus::Successful,
        'amount_minor' => 10000,
    ]);

    $response = new PaymentResponse(PaymentProvider::Stripe, PaymentStatus::Refunded, providerReference: 're_1', amount: 100.0, currency: 'KES');

    $result = app(TransitionPayment::class)($payment, $response, 'webhook');

    expect($result->changed)->toBeTrue();
    expect($payment->refresh()->status)->toBe(PaymentStatus::Refunded);

    Event::assertDispatched(PaymentRefunded::class, fn (PaymentRefunded $e) => $e->payment->is($payment) && $e->response->successful());
});

it('fires PaymentReversed with the response when a payment transitions to Reversed', function () {
    Event::fake([PaymentReversed::class]);

    $payment = Payment::create([
        'provider' => PaymentProvider::Mpesa,
        'category' => PaymentProvider::Mpesa->category(),
        'reference' => 'TX_TRANS_REVERSE',
        'status' => PaymentStatus::Successful,
        'amount_minor' => 10000,
    ]);

    $response = new PaymentResponse(PaymentProvider::Mpesa, PaymentStatus::Reversed, providerReference: 'REV_1');

    $result = app(TransitionPayment::class)($payment, $response, 'webhook');

    expect($result->changed)->toBeTrue();
    expect($payment->refresh()->status)->toBe(PaymentStatus::Reversed);

    Event::assertDispatched(PaymentReversed::class, fn (PaymentReversed $e) => $e->payment->is($payment) && $e->response->providerReference === 'REV_1');
});
