<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Paysasa\Payments\Actions\ProcessRefund;
use Paysasa\Payments\Actions\TransitionPayment;
use Paysasa\Payments\Contracts\PaymentRepository;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\RefundRequest;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Enums\Currency;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Enums\RefundStatus;
use Paysasa\Payments\Enums\WebhookStatus;
use Paysasa\Payments\Events\PaymentSuccessful;
use Paysasa\Payments\Exceptions\InvalidConfigurationException;
use Paysasa\Payments\Exceptions\ProviderApiException;
use Paysasa\Payments\Exceptions\UnsupportedOperationException;
use Paysasa\Payments\Exceptions\WebhookVerificationException;
use Paysasa\Payments\Facades\Payment;
use Paysasa\Payments\Jobs\DispatchWebhookJob;
use Paysasa\Payments\Jobs\VerifyPaymentStatusJob;
use Paysasa\Payments\Managers\PaymentManager;
use Paysasa\Payments\Models\Payment as PaymentModel;
use Paysasa\Payments\Models\Refund;
use Paysasa\Payments\Models\Webhook;
use Paysasa\Payments\Services\Airtel\AirtelAuthenticator;
use Paysasa\Payments\Webhooks\Verifiers\AllowlistWebhookVerifier;

const AIRTEL_BASE = 'openapiuat.airtel.africa';

beforeEach(function () {
    config()->set('paysasa.drivers.airtel.client_id', 'airtel-client');
    config()->set('paysasa.drivers.airtel.client_secret', 'airtel-secret');
    config()->set('paysasa.drivers.airtel.country', 'KE');
    config()->set('paysasa.drivers.airtel.currency', 'KES');
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Fakes Airtel's token endpoint plus whichever other routes a test needs (listed most specific first). */
function fakeAirtel(array $routes = []): void
{
    $fakes = [AIRTEL_BASE.'/auth/oauth2/token' => Http::response(['access_token' => 'airtel-token', 'expires_in' => 3600])];

    foreach ($routes as $path => $response) {
        $fakes[AIRTEL_BASE.$path] = $response;
    }

    Http::fake($fakes);
}

function airtelDriver(): \Paysasa\Payments\Contracts\PaymentDriver
{
    return app(PaymentManager::class)->driverInstance('airtel');
}

function airtelEnquiry(string $status, ?string $airtelMoneyId = 'MP240101.1200.A12345', string $id = 'AIR_TXN_1'): array
{
    return [
        'data' => ['transaction' => ['id' => $id, 'status' => $status, 'airtel_money_id' => $airtelMoneyId, 'message' => 'Enquiry result']],
        'status' => ['code' => '200', 'success' => true, 'message' => 'SUCCESS'],
    ];
}

function airtelPendingPayment(string $providerReference = 'AIR_TXN_1', ?string $receipt = null, PaymentStatus $status = PaymentStatus::Pending): PaymentModel
{
    return PaymentModel::create([
        'provider' => PaymentProvider::Airtel,
        'category' => PaymentProvider::Airtel->category(),
        'reference' => 'AIRTEL_'.$providerReference,
        'provider_reference' => $providerReference,
        'status' => $status,
        'currency' => 'KES',
        'amount_minor' => 50000, // 500.00 KES
        'receipt_number' => $receipt,
    ]);
}

function airtelCallback(string $statusCode, string $id = 'AIR_TXN_1', ?string $airtelMoneyId = 'MP240101.1200.A12345'): array
{
    return ['transaction' => ['id' => $id, 'status_code' => $statusCode, 'airtel_money_id' => $airtelMoneyId, 'message' => 'Callback message']];
}

function processAirtelCallback(array $callback): Webhook
{
    $webhook = Webhook::create(['provider' => 'airtel', 'status' => WebhookStatus::Received, 'payload' => $callback]);

    (new DispatchWebhookJob($webhook, [], (string) json_encode($callback)))->handle(
        app(PaymentManager::class),
        app(PaymentRepository::class),
        app(TransitionPayment::class),
    );

    return $webhook->refresh();
}

function airtelRefundRequest(PaymentModel $payment, ?float $amount = null): RefundRequest
{
    return new RefundRequest((string) $payment->uuid, $payment->provider_reference, $amount, 'test');
}

// ---------------------------------------------------------------- charge

it('sends the subscriber number without the country code and the country/currency headers', function () {
    Queue::fake([VerifyPaymentStatusJob::class]);
    fakeAirtel(['/merchant/v1/payments/' => Http::response([
        'data' => ['transaction' => ['id' => 'AIR_TXN_1', 'status' => 'Success']],
        'status' => ['code' => '200', 'success' => true, 'message' => 'Success'],
    ])]);

    $response = Payment::driver('airtel')->amount(2500)->currency('KES')->phone('0712345678')->reference('AIR1')->charge();

    expect($response->pending())->toBeTrue();

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/merchant/v1/payments/')) {
            return false;
        }

        $data = $request->data();

        return $data['subscriber']['msisdn'] === '712345678'
            && $data['subscriber']['country'] === 'KE'
            && $data['subscriber']['currency'] === 'KES'
            && $data['transaction']['country'] === 'KE'
            && $data['transaction']['currency'] === 'KES'
            && $data['transaction']['amount'] == 2500
            && $data['reference'] === 'AIR1'
            && $request->header('X-Country') === ['KE']
            && $request->header('X-Currency') === ['KES']
            && $request->header('Authorization') === ['Bearer airtel-token'];
    });

    $payment = PaymentModel::where('reference', 'AIR1')->firstOrFail();

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->provider_reference)->toBe('AIR_TXN_1');

    Queue::assertPushed(VerifyPaymentStatusJob::class);
});

it('fails the payment when Airtel rejects the collection request', function () {
    Queue::fake([VerifyPaymentStatusJob::class]);
    fakeAirtel(['/merchant/v1/payments/' => Http::response([
        'status' => ['code' => '400', 'success' => false, 'message' => 'Subscriber is not eligible'],
    ])]);

    $response = Payment::driver('airtel')->amount(100)->phone('0712345678')->reference('AIR2')->charge();

    expect($response->status)->toBe(PaymentStatus::Failed)
        ->and($response->message)->toContain('not eligible')
        ->and(PaymentModel::where('reference', 'AIR2')->firstOrFail()->status)->toBe(PaymentStatus::Failed);

    Queue::assertNotPushed(VerifyPaymentStatusJob::class);
});

it('refuses a charge in a currency Airtel is not configured for, before contacting Airtel', function () {
    Http::fake();

    expect(fn () => Payment::driver('airtel')->amount(10)->currency('USD')->phone('0712345678')->reference('AIR3')->charge())
        ->toThrow(InvalidConfigurationException::class, 'AIRTEL_CURRENCY');

    Http::assertNothingSent();

    // The payment must not be left orphaned as Pending.
    expect(PaymentModel::where('reference', 'AIR3')->firstOrFail()->status)->toBe(PaymentStatus::Failed);
});

it('requires a phone number to charge', function () {
    Http::fake();

    expect(fn () => airtelDriver()->charge(new ChargeRequest(PaymentProvider::Airtel, 100, Currency::KES)))
        ->toThrow(InvalidConfigurationException::class);

    Http::assertNothingSent();
});

// ---------------------------------------------------------------- verify

it('maps every Airtel enquiry status onto a payment status', function (string $code, PaymentStatus $expected) {
    fakeAirtel(['/standard/v1/payments/*' => Http::response(airtelEnquiry($code))]);

    expect(airtelDriver()->verify('AIR_TXN_1')->status)->toBe($expected);
})->with([
    'TS success' => ['TS', PaymentStatus::Successful],
    'TF failed' => ['TF', PaymentStatus::Failed],
    'TE expired' => ['TE', PaymentStatus::Expired],
    'TIP in progress' => ['TIP', PaymentStatus::Pending],
    'TA ambiguous' => ['TA', PaymentStatus::Pending],
    'unknown code' => ['???', PaymentStatus::Pending],
]);

it('exposes the Airtel Money ID as the receipt number on a successful enquiry', function () {
    fakeAirtel(['/standard/v1/payments/*' => Http::response(airtelEnquiry('TS', 'MP999.1'))]);

    $response = airtelDriver()->verify('AIR_TXN_1');

    expect($response->receiptNumber)->toBe('MP999.1')
        ->and($response->providerReference)->toBe('AIR_TXN_1');
});

it('url-encodes the transaction id in the enquiry path', function () {
    fakeAirtel(['/standard/v1/payments/*' => Http::response(airtelEnquiry('TIP'))]);

    airtelDriver()->verify('a/b c');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/standard/v1/payments/a%2Fb%20c'));
});

// ---------------------------------------------------------------- callback parsing

it('parses callbacks using status_code and falls back to the legacy status key', function (array $transaction, PaymentStatus $expected) {
    $payload = new WebhookPayload(PaymentProvider::Airtel, [], '{}', ['transaction' => $transaction]);

    $response = airtelDriver()->handleCallback($payload);

    expect($response->status)->toBe($expected)
        ->and($response->providerReference)->toBe('AIR_TXN_1');
})->with([
    'status_code TS' => [['id' => 'AIR_TXN_1', 'status_code' => 'TS'], PaymentStatus::Successful],
    'status_code TF' => [['id' => 'AIR_TXN_1', 'status_code' => 'TF'], PaymentStatus::Failed],
    'status_code TIP stays pending' => [['id' => 'AIR_TXN_1', 'status_code' => 'TIP'], PaymentStatus::Pending],
    'status_code TA stays pending' => [['id' => 'AIR_TXN_1', 'status_code' => 'TA'], PaymentStatus::Pending],
    'legacy status key' => [['id' => 'AIR_TXN_1', 'status' => 'TS'], PaymentStatus::Successful],
]);

it('reads the Airtel Money ID from a success callback as the receipt number', function () {
    $payload = new WebhookPayload(PaymentProvider::Airtel, [], '{}', airtelCallback('TS', 'AIR_TXN_1', 'MP777.1'));

    expect(airtelDriver()->handleCallback($payload)->receiptNumber)->toBe('MP777.1');
});

// ---------------------------------------------------------------- callback intake and confirmation

it('accepts an unsigned Airtel callback outside production and queues processing', function () {
    Bus::fake();

    $this->postJson('/paysasa/webhooks/airtel', airtelCallback('TS'))->assertStatus(200);

    expect(Webhook::where('provider', 'airtel')->where('status', WebhookStatus::Received->value)->exists())->toBeTrue();
    Bus::assertDispatched(DispatchWebhookJob::class);
});

it('rejects a callback from an address that is not on the allowlist', function () {
    Bus::fake();
    config()->set('paysasa.drivers.airtel.webhook_ip_allowlist', ['10.9.8.7']);

    $this->postJson('/paysasa/webhooks/airtel', airtelCallback('TS'))->assertStatus(401);

    Bus::assertNotDispatched(DispatchWebhookJob::class);
});

it('accepts a callback from an allowlisted address', function () {
    Bus::fake();
    config()->set('paysasa.drivers.airtel.webhook_ip_allowlist', ['127.0.0.1']);

    $this->postJson('/paysasa/webhooks/airtel', airtelCallback('TS'))->assertStatus(200);

    Bus::assertDispatched(DispatchWebhookJob::class);
});

it('settles a success callback once Airtel confirms it with an enquiry', function () {
    Event::fake([PaymentSuccessful::class]);
    fakeAirtel(['/standard/v1/payments/*' => Http::response(airtelEnquiry('TS'))]);

    $payment = airtelPendingPayment();
    $webhook = processAirtelCallback(airtelCallback('TS'));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Successful)
        ->and($payment->receipt_number)->toBe('MP240101.1200.A12345')
        ->and($webhook->status)->toBe(WebhookStatus::Processed);

    Event::assertDispatched(PaymentSuccessful::class, 1);
});

it('does not settle a forged success callback when Airtel says the payment failed', function () {
    Event::fake([PaymentSuccessful::class]);
    fakeAirtel(['/standard/v1/payments/*' => Http::response(airtelEnquiry('TF', null))]);

    $payment = airtelPendingPayment();
    $webhook = processAirtelCallback(airtelCallback('TS'));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->receipt_number)->toBeNull()
        ->and($webhook->error_message)->toContain('Callback claimed success');

    Event::assertNotDispatched(PaymentSuccessful::class);
});

it('leaves the payment pending and lets the queue retry when the confirmation call errors', function () {
    fakeAirtel(['/standard/v1/payments/*' => Http::response(['status' => ['success' => false]], 503)]);

    $payment = airtelPendingPayment();

    expect(fn () => processAirtelCallback(airtelCallback('TS')))->toThrow(ProviderApiException::class);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('does not confirm callbacks that are not claiming success', function (string $code, PaymentStatus $expected) {
    Http::fake();

    $payment = airtelPendingPayment();
    processAirtelCallback(airtelCallback($code));

    Http::assertNothingSent();
    expect($payment->refresh()->status)->toBe($expected);
})->with([
    'TIP keeps the payment pending' => ['TIP', PaymentStatus::Pending],
    'TF fails the payment' => ['TF', PaymentStatus::Failed],
]);

it('does not call Airtel when callback confirmation is switched off', function () {
    config()->set('paysasa.drivers.airtel.confirm_callbacks', false);
    Http::fake();

    $payment = airtelPendingPayment();
    processAirtelCallback(airtelCallback('TS'));

    Http::assertNothingSent();
    expect($payment->refresh()->status)->toBe(PaymentStatus::Successful);
});

// ---------------------------------------------------------------- unsigned-webhook verifier

it('fails closed in production when neither an IP allowlist nor a secret route alias is configured', function () {
    $payload = new WebhookPayload(PaymentProvider::Airtel, [], '{}', []);

    (new AllowlistWebhookVerifier('airtel', [], false, 'production'))->verify($payload);
})->throws(WebhookVerificationException::class, 'misconfigured');

it('accepts an unsigned webhook in production on a secret route alias', function () {
    $payload = new WebhookPayload(PaymentProvider::Airtel, [], '{}', []);

    (new AllowlistWebhookVerifier('airtel', [], true, 'production'))->verify($payload);
})->throwsNoExceptions();

it('accepts an unsigned webhook outside production without configuration', function () {
    $payload = new WebhookPayload(PaymentProvider::Airtel, [], '{}', []);

    (new AllowlistWebhookVerifier('airtel', [], false, 'local'))->verify($payload);
})->throwsNoExceptions();

// ---------------------------------------------------------------- refunds

it('refunds by the Airtel Money ID (the receipt number), not the merchant transaction id', function () {
    fakeAirtel(['/standard/v1/payments/refund' => Http::response([
        'data' => ['transaction' => ['id' => 'RF_1', 'status' => 'SUCCESS']],
        'status' => ['code' => '200', 'success' => true, 'message' => 'Refund successful'],
    ])]);

    $payment = airtelPendingPayment('AIR_TXN_1', 'MP240101.1200.A12345', PaymentStatus::Successful);

    app(ProcessRefund::class)->execute(airtelDriver(), $payment, airtelRefundRequest($payment));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/standard/v1/payments/refund')
        && $request->data() === ['transaction' => ['airtel_money_id' => 'MP240101.1200.A12345']]);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and(Refund::first()->status)->toBe(RefundStatus::Completed);
});

it('treats an explicit amount equal to the whole payment as a full refund', function () {
    fakeAirtel(['/standard/v1/payments/refund' => Http::response(['status' => ['success' => true]])]);

    $payment = airtelPendingPayment('AIR_TXN_1', 'MP1', PaymentStatus::Successful);

    app(ProcessRefund::class)->execute(airtelDriver(), $payment, airtelRefundRequest($payment, 500.0));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
});

it('rejects a partial refund because Airtel only refunds in full, and frees the reservation', function () {
    Http::fake();

    $payment = airtelPendingPayment('AIR_TXN_1', 'MP1', PaymentStatus::Successful);

    expect(fn () => app(ProcessRefund::class)->execute(airtelDriver(), $payment, airtelRefundRequest($payment, 100.0)))
        ->toThrow(UnsupportedOperationException::class, 'partial refund');

    Http::assertNothingSent();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Successful)
        ->and(Refund::first()->status)->toBe(RefundStatus::Failed);
});

it('rejects a refund for a payment with no Airtel Money ID', function () {
    Http::fake();

    $payment = airtelPendingPayment('AIR_TXN_1', null, PaymentStatus::Successful);

    expect(fn () => app(ProcessRefund::class)->execute(airtelDriver(), $payment, airtelRefundRequest($payment)))
        ->toThrow(UnsupportedOperationException::class, 'Airtel Money ID');

    Http::assertNothingSent();
});

it('records a refund Airtel reports as unsuccessful as Failed', function () {
    fakeAirtel(['/standard/v1/payments/refund' => Http::response([
        'status' => ['code' => '400', 'success' => false, 'message' => 'Refund window elapsed'],
    ])]);

    $payment = airtelPendingPayment('AIR_TXN_1', 'MP1', PaymentStatus::Successful);

    app(ProcessRefund::class)->execute(airtelDriver(), $payment, airtelRefundRequest($payment));

    expect(Refund::first()->status)->toBe(RefundStatus::Failed)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Successful);
});

// ---------------------------------------------------------------- payouts

function airtelPayoutRequest(): ChargeRequest
{
    return new ChargeRequest(PaymentProvider::Airtel, 100, Currency::KES, phone: '0712345678', reference: 'PAYOUT1');
}

it('refuses a payout when the disbursement PIN or public key is missing', function () {
    Http::fake();

    expect(fn () => airtelDriver()->payout(airtelPayoutRequest()))
        ->toThrow(InvalidConfigurationException::class, 'disbursement_pin');

    Http::assertNothingSent();
});

it('RSA-encrypts the disbursement PIN with the configured public key', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privatePem);
    $publicPem = openssl_pkey_get_details($key)['key'];

    // Airtel distributes the key as bare base64; the driver must add the PEM armour itself.
    $bare = trim(str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----'], '', $publicPem));

    config()->set('paysasa.drivers.airtel.disbursement_pin', '4321');
    config()->set('paysasa.drivers.airtel.public_key', $bare);

    fakeAirtel(['/standard/v1/disbursements/' => Http::response([
        'data' => ['transaction' => ['id' => 'DISB_1']],
        'status' => ['code' => '200', 'success' => true, 'message' => 'Accepted'],
    ])]);

    $response = airtelDriver()->payout(airtelPayoutRequest());

    expect($response->pending())->toBeTrue()
        ->and($response->providerReference)->toBe('DISB_1');

    $sent = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/standard/v1/disbursements/'))->first()[0];

    expect($sent->data()['payee']['msisdn'])->toBe('712345678');

    $plain = '';
    expect(openssl_private_decrypt((string) base64_decode($sent->data()['pin'], true), $plain, $privatePem, OPENSSL_PKCS1_PADDING))->toBeTrue()
        ->and($plain)->toBe('4321');
});

it('fails a payout Airtel rejects', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    config()->set('paysasa.drivers.airtel.disbursement_pin', '4321');
    config()->set('paysasa.drivers.airtel.public_key', openssl_pkey_get_details($key)['key']);

    fakeAirtel(['/standard/v1/disbursements/' => Http::response(['status' => ['success' => false, 'message' => 'Wrong PIN']])]);

    $response = airtelDriver()->payout(airtelPayoutRequest());

    expect($response->status)->toBe(PaymentStatus::Failed)
        ->and($response->message)->toBe('Wrong PIN');
});

it('refuses a payout when the public key is not a valid RSA key', function () {
    config()->set('paysasa.drivers.airtel.disbursement_pin', '4321');
    config()->set('paysasa.drivers.airtel.public_key', 'not-a-key');
    Http::fake();

    expect(fn () => airtelDriver()->payout(airtelPayoutRequest()))
        ->toThrow(InvalidConfigurationException::class, 'AIRTEL_PUBLIC_KEY');

    Http::assertNothingSent();
});

// ---------------------------------------------------------------- authentication

it('caches the token for the lifetime Airtel reports, not a fixed guess', function () {
    Carbon::setTestNow('2026-01-01 12:00:00');
    Http::fake(['*' => Http::response(['access_token' => 'tok', 'expires_in' => 100])]);

    $auth = new AirtelAuthenticator('https://'.AIRTEL_BASE, 'id', 'secret');

    expect($auth->token())->toBe('tok')
        ->and($auth->token())->toBe('tok');
    Http::assertSentCount(1);

    // 100s lifetime minus the 60s safety margin = cached for 40s.
    Carbon::setTestNow('2026-01-01 12:00:30');
    $auth->token();
    Http::assertSentCount(1);

    Carbon::setTestNow('2026-01-01 12:00:45');
    $auth->token();
    Http::assertSentCount(2);
});

it('fails clearly when the token response carries no access token', function () {
    Http::fake(['*' => Http::response(['error' => 'invalid_client'])]);

    (new AirtelAuthenticator('https://'.AIRTEL_BASE, 'id', 'secret'))->token();
})->throws(ProviderApiException::class, 'access_token');

it('refreshes the token and retries once when Airtel answers 401', function () {
    Http::fake([
        AIRTEL_BASE.'/auth/oauth2/token' => Http::sequence()
            ->push(['access_token' => 'stale-token', 'expires_in' => 3600])
            ->push(['access_token' => 'fresh-token', 'expires_in' => 3600]),
        AIRTEL_BASE.'/standard/v1/payments/*' => Http::sequence()
            ->push(['message' => 'Invalid token'], 401)
            ->push(airtelEnquiry('TS')),
    ]);

    $response = airtelDriver()->verify('AIR_TXN_1');

    expect($response->status)->toBe(PaymentStatus::Successful);

    $enquiries = Http::recorded(fn (Request $request) => str_contains($request->url(), '/standard/v1/payments/'))->values();

    expect($enquiries)->toHaveCount(2)
        ->and($enquiries[0][0]->header('Authorization'))->toBe(['Bearer stale-token'])
        ->and($enquiries[1][0]->header('Authorization'))->toBe(['Bearer fresh-token']);
});
