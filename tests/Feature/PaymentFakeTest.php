<?php

declare(strict_types=1);

use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Facades\Payment;

it('fakes a charge without hitting a real provider and records it for assertions', function () {
    Payment::fake();

    $response = Payment::driver('mpesa')
        ->amount(2500)
        ->currency('KES')
        ->phone('254712345678')
        ->reference('INV1001')
        ->charge();

    expect($response->successful())->toBeTrue();

    Payment::assertCharged(fn ($request) => $request->amount === 2500.0 && $request->reference === 'INV1001');
    Payment::assertChargedOn('mpesa');
    Payment::assertChargedTimes(1);
});

it('lets tests queue specific responses to exercise failure handling', function () {
    $fake = Payment::fake();
    $fake->queueResponse(PaymentResponse::makeFailed(PaymentProvider::Mpesa, message: 'Insufficient funds'));

    $response = Payment::driver('mpesa')->amount(100)->phone('254700000000')->charge();

    expect($response->failed())->toBeTrue()
        ->and($response->message())->toBe('Insufficient funds');
});

it('asserts nothing was charged when no charge is made', function () {
    Payment::fake();

    Payment::assertNothingCharged();
});
