<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Paysasa\Payments\Exceptions\InvalidPhoneNumberException;
use Paysasa\Payments\Facades\Payment;
use Paysasa\Payments\Middleware\EnsureIdempotencyKey;
use Paysasa\Payments\Models\Payment as PaymentModel;
use Paysasa\Payments\Models\PaymentAttempt;

it('closes the payment out as Failed when the driver rejects the request before contacting the provider', function () {
    Http::fake();

    expect(fn () => Payment::driver('mpesa')
        ->amount(1000)
        ->phone('not-a-phone-number')
        ->reference('PREFLIGHT_1')
        ->charge()
    )->toThrow(InvalidPhoneNumberException::class);

    // Nothing may have been sent to Safaricom...
    Http::assertNothingSent();

    // ...and the payment must not be left orphaned as Pending.
    $payment = PaymentModel::where('reference', 'PREFLIGHT_1')->firstOrFail();

    expect($payment->status->value)->toBe('failed')
        ->and(PaymentAttempt::where('payment_id', $payment->id)->count())->toBe(1);
});

it('requires a non-blank X-Idempotency-Key on every state-changing request', function (string $method, ?string $key, int $expected) {
    $request = Request::create('/api/payments', $method);

    if ($key !== null) {
        $request->headers->set('X-Idempotency-Key', $key);
    }

    $response = (new EnsureIdempotencyKey())->handle($request, fn () => response('ok'));

    expect($response->getStatusCode())->toBe($expected);
})->with([
    'POST without a key' => ['POST', null, 422],
    'POST with a blank key' => ['POST', '   ', 422],
    'PUT without a key' => ['PUT', null, 422],
    'PATCH without a key' => ['PATCH', null, 422],
    'DELETE without a key' => ['DELETE', null, 422],
    'POST with a key' => ['POST', 'abc-123', 200],
    'GET needs no key' => ['GET', null, 200],
]);
