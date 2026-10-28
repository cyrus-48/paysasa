<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Paysasa\Payments\DTOs\CustomerData;
use Paysasa\Payments\DTOs\RefundRequest;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Enums\RefundStatus;
use Paysasa\Payments\Enums\WebhookStatus;
use Paysasa\Payments\Facades\Payment;
use Paysasa\Payments\Jobs\VerifyPaymentStatusJob;
use Paysasa\Payments\Managers\PaymentManager;
use Paysasa\Payments\Models\Payment as PaymentModel;
use Paysasa\Payments\Models\Webhook;

const PAYSTACK_TEST_SECRET = 'sk_test_paystack_secret';

function paystackVerifyResponse(string $status, int $amountMinor = 250000, string $currency = 'KES', string $reference = 'PS_REF'): array
{
    return ['status' => true, 'data' => [
        'status' => $status, 'reference' => $reference, 'amount' => $amountMinor, 'currency' => $currency,
    ]];
}

function paystackPendingPayment(string $reference, int $amountMinor = 250000): PaymentModel
{
    return PaymentModel::create([
        'provider' => PaymentProvider::Paystack,
        'category' => PaymentProvider::Paystack->category(),
        'reference' => $reference,
        'provider_reference' => $reference,
        'status' => PaymentStatus::Pending,
        'currency' => 'KES',
        'amount_minor' => $amountMinor,
    ]);
}

/** POST a correctly signed (or deliberately mis-signed) Paystack webhook to the package route. */
function postPaystackWebhook($test, array $body, ?string $signature = null)
{
    $raw = (string) json_encode($body);

    return $test->call('POST', '/paysasa/webhooks/paystack', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? hash_hmac('sha512', $raw, PAYSTACK_TEST_SECRET),
    ], $raw);
}

beforeEach(function () {
    config()->set('paysasa.drivers.paystack.secret_key', PAYSTACK_TEST_SECRET);
});

// --- charge() ---------------------------------------------------------------

it('initializes a transaction in minor units and returns the checkout URL as a pending payment', function () {
    Queue::fake([VerifyPaymentStatusJob::class]);
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123', 'reference' => 'PS_REF_1'],
    ])]);

    $response = Payment::driver('paystack')
        ->amount(2500)
        ->currency('KES')
        ->reference('PS_REF_1')
        ->customer(new CustomerData(email: 'jane@example.com'))
        ->charge();

    expect($response->pending())->toBeTrue()
        ->and($response->metadata['authorization_url'])->toBe('https://checkout.paystack.com/abc123');

    $payment = PaymentModel::where('reference', 'PS_REF_1')->firstOrFail();
    expect($payment->provider_reference)->toBe('PS_REF_1')
        ->and($payment->status)->toBe(PaymentStatus::Pending);

    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_ends_with($request->url(), '/transaction/initialize')
            && $data['amount'] === 250000
            && $data['currency'] === 'KES'
            && $data['reference'] === 'PS_REF_1'
            && $data['email'] === 'jane@example.com'
            // nulls and empty metadata must not be sent
            && ! array_key_exists('callback_url', $data)
            && ! array_key_exists('metadata', $data);
    });
});

it('uses a syntactically valid placeholder email when the customer has none', function () {
    Queue::fake([VerifyPaymentStatusJob::class]);
    config()->set('app.url', 'not-a-url');
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://x']])]);

    Payment::driver('paystack')->amount(100)->currency('KES')->reference('PS_NOEMAIL')->charge();

    Http::assertSent(fn ($request) => filter_var($request->data()['email'], FILTER_VALIDATE_EMAIL) !== false);
});

it('reports a failed payment when Paystack rejects the initialization', function () {
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => false, 'message' => 'Invalid key'])]);

    $response = Payment::driver('paystack')->amount(100)->currency('KES')->reference('PS_REJECTED')->charge();

    expect($response->failed())->toBeTrue()
        ->and(PaymentModel::where('reference', 'PS_REJECTED')->firstOrFail()->status)->toBe(PaymentStatus::Failed);
});

// --- verify() ---------------------------------------------------------------

it('maps Paystack transaction statuses to payment statuses', function (string $paystackStatus, PaymentStatus $expected) {
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(paystackVerifyResponse($paystackStatus))]);

    $response = app(PaymentManager::class)->driverInstance('paystack')->verify('PS_REF');

    expect($response->status)->toBe($expected);
})->with([
    'success' => ['success', PaymentStatus::Successful],
    'failed' => ['failed', PaymentStatus::Failed],
    'abandoned' => ['abandoned', PaymentStatus::Cancelled],
    'ongoing' => ['ongoing', PaymentStatus::Pending],
    'pending' => ['pending', PaymentStatus::Pending],
]);

it('reports the settled amount in major units', function () {
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(paystackVerifyResponse('success', 250000))]);

    $response = app(PaymentManager::class)->driverInstance('paystack')->verify('PS_REF');

    expect($response->amount)->toBe(2500.0)->and($response->currency)->toBe('KES');
});

// --- webhooks ---------------------------------------------------------------

it('never trusts the webhook body and re-verifies the transaction with Paystack', function () {
    // The body claims success; Paystack says the payment failed.
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(paystackVerifyResponse('failed'))]);

    $payload = new WebhookPayload(PaymentProvider::Paystack, [], '{}', [
        'event' => 'charge.success',
        'data' => ['reference' => 'PS_REF', 'status' => 'success', 'amount' => 250000],
    ]);

    $response = app(PaymentManager::class)->driverInstance('paystack')->handleCallback($payload);

    expect($response->status)->toBe(PaymentStatus::Failed);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/transaction/verify/PS_REF'));
});

it('settles a payment end to end from a signed charge.success webhook', function () {
    $payment = paystackPendingPayment('PS_E2E');
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(paystackVerifyResponse('success', 250000, 'KES', 'PS_E2E'))]);

    postPaystackWebhook($this, ['event' => 'charge.success', 'data' => ['reference' => 'PS_E2E']])->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Successful)
        ->and(Webhook::latest('id')->first()->status)->toBe(WebhookStatus::Processed);
});

it('rejects a webhook with a bad signature and does not call Paystack', function () {
    Http::fake();

    postPaystackWebhook($this, ['event' => 'charge.success', 'data' => ['reference' => 'PS_X']], 'deadbeef')->assertStatus(401);

    Http::assertNothingSent();
});

it('ignores event types it does not handle instead of failing on them', function (string $event) {
    $payment = paystackPendingPayment('PS_TRANSFER');
    Http::fake();

    postPaystackWebhook($this, ['event' => $event, 'data' => ['reference' => 'PS_TRANSFER']])->assertOk();

    Http::assertNothingSent();
    expect(Webhook::latest('id')->first()->status)->toBe(WebhookStatus::Ignored)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Pending);
})->with(['transfer.success', 'transfer.failed', 'refund.processed', 'customeridentification.success']);

// --- refund() ---------------------------------------------------------------

it('maps Paystack refund statuses and sends the amount in minor units', function (string $paystackStatus, RefundStatus $expected) {
    Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 77, 'status' => $paystackStatus]])]);

    $response = app(PaymentManager::class)->driverInstance('paystack')
        ->refund(new RefundRequest('txn-1', 'PS_REF', 25.0, currency: 'KES'));

    expect($response->status)->toBe($expected);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/refund')
        && $request['transaction'] === 'PS_REF'
        && (int) $request['amount'] === 2500);
})->with([
    'processed' => ['processed', RefundStatus::Completed],
    'pending' => ['pending', RefundStatus::Processing],
    'processing' => ['processing', RefundStatus::Processing],
    'needs-attention' => ['needs-attention', RefundStatus::Processing],
    'failed' => ['failed', RefundStatus::Failed],
]);
