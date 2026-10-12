<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Events\PaymentExpired;
use Paysasa\Payments\Jobs\VerifyPaymentStatusJob;
use Paysasa\Payments\Models\Payment;

it('sweeps pending and processing payments and dispatches VerifyPaymentStatusJob', function () {
    Queue::fake([VerifyPaymentStatusJob::class]);

    $p1 = Payment::create([
        'provider' => PaymentProvider::Mpesa,
        'category' => PaymentProvider::Mpesa->category(),
        'reference' => 'REC_SWEEP_1',
        'status' => PaymentStatus::Pending,
        'amount_minor' => 1000,
        'last_checked_at' => null,
    ]);

    $p2 = Payment::create([
        'provider' => PaymentProvider::Mpesa,
        'category' => PaymentProvider::Mpesa->category(),
        'reference' => 'REC_SWEEP_2',
        'status' => PaymentStatus::Successful,
        'amount_minor' => 1000,
    ]);

    $this->artisan('paysasa:reconcile')->assertSuccessful();

    Queue::assertPushed(VerifyPaymentStatusJob::class, function ($job) use ($p1) {
        return $job->payment->id === $p1->id;
    });

    Queue::assertNotPushed(VerifyPaymentStatusJob::class, function ($job) use ($p2) {
        return $job->payment->id === $p2->id;
    });
});

it('expires payments past expires_at and fires PaymentExpired event', function () {
    Event::fake([PaymentExpired::class]);

    $payment = Payment::create([
        'provider' => PaymentProvider::Mpesa,
        'category' => PaymentProvider::Mpesa->category(),
        'reference' => 'REC_EXPIRE_1',
        'status' => PaymentStatus::Pending,
        'amount_minor' => 1000,
        'expires_at' => now()->subMinutes(5),
    ]);

    $job = new VerifyPaymentStatusJob($payment, 'mpesa');

    $job->handle(
        app(\Paysasa\Payments\Managers\PaymentManager::class),
        app(\Paysasa\Payments\Actions\VerifyPayment::class),
        app(\Paysasa\Payments\Actions\TransitionPayment::class)
    );

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Expired);

    Event::assertDispatched(PaymentExpired::class, 1);
});
