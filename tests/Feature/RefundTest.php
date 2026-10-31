<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Paysasa\Payments\Actions\ProcessRefund;
use Paysasa\Payments\DTOs\RefundRequest;
use Paysasa\Payments\DTOs\RefundResponse;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Enums\RefundStatus;
use Paysasa\Payments\Events\PaymentRefunded;
use Paysasa\Payments\Exceptions\InvalidAmountException;
use Paysasa\Payments\Exceptions\PaymentNotRefundableException;
use Paysasa\Payments\Exceptions\ProviderApiException;
use Paysasa\Payments\Exceptions\UnsupportedOperationException;
use Paysasa\Payments\Models\Payment;
use Paysasa\Payments\Models\Refund;
use Paysasa\Payments\Testing\FakeDriver;

function refundablePayment(int $minor = 10000, PaymentStatus $status = PaymentStatus::Successful): Payment
{
    return Payment::create([
        'provider' => PaymentProvider::Mpesa,
        'category' => PaymentProvider::Mpesa->category(),
        'reference' => 'RF_'.uniqid(),
        'provider_reference' => 'PR_'.uniqid(),
        'status' => $status,
        'currency' => 'KES',
        'amount_minor' => $minor,
    ]);
}

function refundRequestFor(Payment $payment, ?float $amount = null): RefundRequest
{
    return new RefundRequest((string) $payment->uuid, $payment->provider_reference, $amount, 'test');
}

/** A FakeDriver that records the request it was asked to refund. */
function recordingRefundDriver(): FakeDriver
{
    return new class extends FakeDriver {
        public ?RefundRequest $seen = null;

        public function refund(RefundRequest $request): RefundResponse
        {
            $this->seen = $request;

            return parent::refund($request);
        }
    };
}

it('fully refunds a successful payment and fires PaymentRefunded once', function () {
    Event::fake([PaymentRefunded::class]);
    $payment = refundablePayment(10000);

    app(ProcessRefund::class)->execute(new FakeDriver(), $payment, refundRequestFor($payment));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
    expect(Refund::where('payment_id', $payment->id)->count())->toBe(1);
    expect((int) Refund::first()->amount_minor)->toBe(10000);
    expect(Refund::first()->status)->toBe(RefundStatus::Completed);
    Event::assertDispatched(PaymentRefunded::class, 1);
});

it('moves to PartiallyRefunded then Refunded as refunds add up to the full amount', function () {
    $payment = refundablePayment(10000);
    $action = app(ProcessRefund::class);

    $action->execute(new FakeDriver(), $payment, refundRequestFor($payment, 40.0));
    expect($payment->fresh()->status)->toBe(PaymentStatus::PartiallyRefunded);

    $action->execute(new FakeDriver(), $payment->fresh(), refundRequestFor($payment, 60.0));
    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
});

it('rejects a refund that exceeds the amount paid and records nothing', function () {
    $payment = refundablePayment(10000);

    expect(fn () => app(ProcessRefund::class)->execute(new FakeDriver(), $payment, refundRequestFor($payment, 100.01)))
        ->toThrow(InvalidAmountException::class);

    expect(Refund::count())->toBe(0);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Successful);
});

it('rejects a refund that exceeds what is left after an earlier partial refund', function () {
    $payment = refundablePayment(10000);
    $action = app(ProcessRefund::class);

    $action->execute(new FakeDriver(), $payment, refundRequestFor($payment, 70.0));

    expect(fn () => $action->execute(new FakeDriver(), $payment->fresh(), refundRequestFor($payment, 40.0)))
        ->toThrow(InvalidAmountException::class);

    expect(Refund::count())->toBe(1);
});

it('asks the provider to refund only the remaining balance when no amount is given after a partial refund', function () {
    $payment = refundablePayment(10000);
    $action = app(ProcessRefund::class);

    $action->execute(new FakeDriver(), $payment, refundRequestFor($payment, 25.0));

    $driver = recordingRefundDriver();
    $action->execute($driver, $payment->fresh(), refundRequestFor($payment));

    expect($driver->seen->amount)->toBe(75.0);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
});

it('refuses to refund a payment that was never paid', function (PaymentStatus $status) {
    $payment = refundablePayment(10000, $status);

    expect(fn () => app(ProcessRefund::class)->execute(new FakeDriver(), $payment, refundRequestFor($payment)))
        ->toThrow(PaymentNotRefundableException::class);

    expect(Refund::count())->toBe(0);
})->with([
    'pending' => PaymentStatus::Pending,
    'failed' => PaymentStatus::Failed,
    'already refunded' => PaymentStatus::Refunded,
]);

it('rejects a zero or negative refund amount', function () {
    $payment = refundablePayment(10000);

    expect(fn () => app(ProcessRefund::class)->execute(new FakeDriver(), $payment, refundRequestFor($payment, 0.0)))
        ->toThrow(InvalidAmountException::class);
});

it('releases the reserved amount when the provider definitively rejects the refund', function () {
    $payment = refundablePayment(10000);

    $rejecting = new class extends FakeDriver {
        public function refund(RefundRequest $request): RefundResponse
        {
            throw new ProviderApiException('refund not allowed', 400, ['error' => 'nope']);
        }
    };

    expect(fn () => app(ProcessRefund::class)->execute($rejecting, $payment, refundRequestFor($payment)))
        ->toThrow(ProviderApiException::class);

    expect(Refund::first()->status)->toBe(RefundStatus::Failed);

    // The amount is free again, so a retry through a working driver succeeds.
    app(ProcessRefund::class)->execute(new FakeDriver(), $payment->fresh(), refundRequestFor($payment));
    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
});

it('keeps the amount reserved when the refund outcome is unknown after a timeout', function () {
    $payment = refundablePayment(10000);

    $timingOut = new class extends FakeDriver {
        public function refund(RefundRequest $request): RefundResponse
        {
            throw new ConnectionException('timed out');
        }
    };

    expect(fn () => app(ProcessRefund::class)->execute($timingOut, $payment, refundRequestFor($payment)))
        ->toThrow(ConnectionException::class);

    expect(Refund::first()->status)->toBe(RefundStatus::Pending);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Successful);

    // The first refund may have gone through, so the same amount must not be refunded again.
    expect(fn () => app(ProcessRefund::class)->execute(new FakeDriver(), $payment->fresh(), refundRequestFor($payment)))
        ->toThrow(InvalidAmountException::class);
});

it('passes the receipt number and original amount to the driver for providers that refund by their own id', function () {
    $payment = refundablePayment(10000);
    $payment->update(['receipt_number' => 'RCPT_123']);

    $driver = recordingRefundDriver();
    app(ProcessRefund::class)->execute($driver, $payment->fresh(), refundRequestFor($payment));

    expect($driver->seen->metadata['receipt_number'])->toBe('RCPT_123')
        ->and($driver->seen->metadata['payment_amount'])->toBe(100.0)
        ->and($driver->seen->currency)->toBe('KES');
});

it('releases the reservation when the driver refuses the refund before contacting the provider', function () {
    $payment = refundablePayment(10000);

    $refusing = new class extends FakeDriver {
        public function refund(RefundRequest $request): RefundResponse
        {
            throw UnsupportedOperationException::make('fake', 'partial refund');
        }
    };

    expect(fn () => app(ProcessRefund::class)->execute($refusing, $payment, refundRequestFor($payment, 10.0)))
        ->toThrow(UnsupportedOperationException::class);

    expect(Refund::first()->status)->toBe(RefundStatus::Failed);

    // Nothing was reserved, so the full amount can still be refunded.
    app(ProcessRefund::class)->execute(new FakeDriver(), $payment->fresh(), refundRequestFor($payment));
    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
});
