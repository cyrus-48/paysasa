<?php

declare(strict_types=1);

use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;

it('exposes a uniform successful response regardless of provider', function () {
    $response = PaymentResponse::makeSuccessful(
        PaymentProvider::Mpesa,
        transactionId: 'txn-1',
        providerReference: 'ws_CO_123',
        amount: 2500.0,
        currency: 'KES',
        receiptNumber: 'NLJ7RT61SV',
    );

    expect($response->successful())->toBeTrue()
        ->and($response->failed())->toBeFalse()
        ->and($response->pending())->toBeFalse()
        ->and($response->status())->toBe(PaymentStatus::Successful)
        ->and($response->provider())->toBe(PaymentProvider::Mpesa)
        ->and($response->receiptNumber())->toBe('NLJ7RT61SV');
});

it('exposes a uniform failed response', function () {
    $response = PaymentResponse::makeFailed(PaymentProvider::Stripe, message: 'Card declined');

    expect($response->failed())->toBeTrue()
        ->and($response->successful())->toBeFalse()
        ->and($response->message())->toBe('Card declined');
});

it('treats pending, processing and authorized as non-terminal via the status enum', function () {
    expect(PaymentStatus::Pending->isTerminal())->toBeFalse()
        ->and(PaymentStatus::Processing->isTerminal())->toBeFalse()
        ->and(PaymentStatus::Successful->isTerminal())->toBeTrue()
        ->and(PaymentStatus::Failed->isTerminal())->toBeTrue();
});
