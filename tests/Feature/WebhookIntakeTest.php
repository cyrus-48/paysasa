<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Enums\WebhookStatus;
use Paysasa\Payments\Events\PaymentAmountMismatch;
use Paysasa\Payments\Events\PaymentSuccessful;
use Paysasa\Payments\Events\WebhookUnmatched;
use Paysasa\Payments\Exceptions\WebhookVerificationException;
use Paysasa\Payments\Jobs\DispatchWebhookJob;
use Paysasa\Payments\Models\Payment;
use Paysasa\Payments\Models\Webhook;
use Paysasa\Payments\Webhooks\Verifiers\MpesaSignatureVerifier;

it('rejects oversized webhook bodies with 413 and persists no row', function () {
    config()->set('paysasa.webhooks.max_body_bytes', 100);

    $huge = str_repeat('A', 200);

    $response = $this->call('POST', '/paysasa/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $huge);

    $response->assertStatus(413);
    expect(Webhook::count())->toBe(0);
});

it('redacts sensitive headers when storing webhooks', function () {
    $secret = config('paysasa.drivers.stripe.webhook_secret');
    $body = ['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_test']]];
    $rawBody = json_encode($body);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

    $this->call('POST', '/paysasa/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_Stripe-Signature' => "t={$timestamp},v1={$signature}",
        'HTTP_AUTHORIZATION' => 'Bearer sensitive_secret_key',
        'HTTP_COOKIE' => 'session_cookie=super_secret',
    ], $rawBody);

    $webhook = Webhook::where('provider', 'stripe')->first();
    expect($webhook)->not->toBeNull()
        ->and(array_key_exists('authorization', $webhook->headers))->toBeFalse()
        ->and(array_key_exists('cookie', $webhook->headers))->toBeFalse();
});

it('detects duplicate webhooks and responds with 200 ignored', function () {
    $secret = config('paysasa.drivers.stripe.webhook_secret');
    $body = ['id' => 'evt_dup_1', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_test']]];
    $rawBody = json_encode($body);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

    $res1 = $this->call('POST', '/paysasa/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_Stripe-Signature' => "t={$timestamp},v1={$signature}",
    ], $rawBody);
    $res1->assertStatus(200);

    $res2 = $this->call('POST', '/paysasa/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_Stripe-Signature' => "t={$timestamp},v1={$signature}",
    ], $rawBody);
    $res2->assertStatus(200);
    expect($res2->json('message'))->toBe('Duplicate webhook ignored');
});

it('fails closed in production when M-Pesa IP allowlist is empty and route is not opaque', function () {
    $verifier = new MpesaSignatureVerifier(ipAllowlist: [], isOpaqueRoute: false, environment: 'production');
    $payload = new WebhookPayload(PaymentProvider::Mpesa, [], '{}', []);

    expect(fn () => $verifier->verify($payload))->toThrow(WebhookVerificationException::class);
});

it('passes M-Pesa verification in production if opaque route alias is used', function () {
    $verifier = new MpesaSignatureVerifier(ipAllowlist: [], isOpaqueRoute: true, environment: 'production');
    $payload = new WebhookPayload(PaymentProvider::Mpesa, [], '{}', []);

    // Does not throw
    $verifier->verify($payload);
    expect(true)->toBeTrue();
});

it('detects amount mismatch in callback and leaves payment Processing', function () {
    Event::fake([PaymentAmountMismatch::class]);

    $payment = Payment::create([
        'provider' => PaymentProvider::Mpesa,
        'category' => PaymentProvider::Mpesa->category(),
        'reference' => 'TX_MISMATCH',
        'status' => PaymentStatus::Pending,
        'amount_minor' => 50000, // 500.00 KES expected
    ]);

    $callback = ['Body' => ['stkCallback' => [
        'CheckoutRequestID' => 'ws_MISMATCH_1',
        'ResultCode' => 0,
        'CallbackMetadata' => ['Item' => [
            ['Name' => 'Amount', 'Value' => 400], // 400 instead of 500
            ['Name' => 'MpesaReceiptNumber', 'Value' => 'REC123'],
        ]],
    ]]];

    $webhook = Webhook::create([
        'payment_id' => $payment->id,
        'provider' => 'mpesa',
        'status' => WebhookStatus::Received,
        'payload' => $callback,
    ]);

    (new DispatchWebhookJob($webhook, [], (string) json_encode($callback)))->handle(
        app(\Paysasa\Payments\Managers\PaymentManager::class),
        app(\Paysasa\Payments\Contracts\PaymentRepository::class),
        app(\Paysasa\Payments\Actions\TransitionPayment::class)
    );

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Processing)
        ->and($payment->flags['amount_mismatch'] ?? false)->toBeTrue();

    Event::assertDispatched(PaymentAmountMismatch::class, 1);
});

it('dispatches WebhookUnmatched event when payment matching exhausts retries', function () {
    Event::fake([WebhookUnmatched::class]);

    $callback = ['Body' => ['stkCallback' => [
        'CheckoutRequestID' => 'ws_UNMATCHED_NEVER_FOUND',
        'ResultCode' => 0,
        'CallbackMetadata' => ['Item' => [
            ['Name' => 'Amount', 'Value' => 100],
        ]],
    ]]];

    $webhook = Webhook::create([
        'provider' => 'mpesa',
        'status' => WebhookStatus::Received,
        'payload' => $callback,
    ]);

    $job = new DispatchWebhookJob($webhook, [], (string) json_encode($callback));
    $job->tries = 1;

    $job->handle(
        app(\Paysasa\Payments\Managers\PaymentManager::class),
        app(\Paysasa\Payments\Contracts\PaymentRepository::class),
        app(\Paysasa\Payments\Actions\TransitionPayment::class)
    );

    $webhook->refresh();
    expect($webhook->status)->toBe(WebhookStatus::Unmatched);

    Event::assertDispatched(WebhookUnmatched::class, 1);
});

function runWebhookJob(Webhook $webhook, array $callback): void
{
    (new DispatchWebhookJob($webhook, [], (string) json_encode($callback)))->handle(
        app(\Paysasa\Payments\Managers\PaymentManager::class),
        app(\Paysasa\Payments\Contracts\PaymentRepository::class),
        app(\Paysasa\Payments\Actions\TransitionPayment::class)
    );
}

function stkCallback(string $checkoutRequestId, int|float $amount, int $resultCode = 0): array
{
    return ['Body' => ['stkCallback' => [
        'CheckoutRequestID' => $checkoutRequestId,
        'ResultCode' => $resultCode,
        'CallbackMetadata' => ['Item' => [
            ['Name' => 'Amount', 'Value' => $amount],
            ['Name' => 'MpesaReceiptNumber', 'Value' => 'REC_OK_1'],
        ]],
    ]]];
}

it('settles the payment and fires PaymentSuccessful once when a matching STK callback is processed', function () {
    Event::fake([PaymentSuccessful::class]);

    $payment = Payment::create([
        'provider' => PaymentProvider::Mpesa,
        'category' => PaymentProvider::Mpesa->category(),
        'reference' => 'TX_E2E_OK',
        'provider_reference' => 'ws_CO_E2E_OK',
        'status' => PaymentStatus::Pending,
        'amount_minor' => 50000, // 500.00 KES
    ]);

    $callback = stkCallback('ws_CO_E2E_OK', 500);
    $webhook = Webhook::create(['provider' => 'mpesa', 'status' => WebhookStatus::Received, 'payload' => $callback]);

    runWebhookJob($webhook, $callback);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Successful)
        ->and($payment->receipt_number)->toBe('REC_OK_1');

    expect($webhook->refresh()->status)->toBe(WebhookStatus::Processed);

    Event::assertDispatched(PaymentSuccessful::class, 1);
});

it('does not downgrade an already-successful payment when a mismatching callback arrives', function () {
    Event::fake([PaymentAmountMismatch::class]);

    $payment = Payment::create([
        'provider' => PaymentProvider::Mpesa,
        'category' => PaymentProvider::Mpesa->category(),
        'reference' => 'TX_NO_DOWNGRADE',
        'provider_reference' => 'ws_CO_NO_DOWNGRADE',
        'status' => PaymentStatus::Successful,
        'amount_minor' => 50000,
    ]);

    $callback = stkCallback('ws_CO_NO_DOWNGRADE', 400); // does not match the 500.00 charged
    $webhook = Webhook::create(['provider' => 'mpesa', 'status' => WebhookStatus::Received, 'payload' => $callback]);

    runWebhookJob($webhook, $callback);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Successful)
        ->and($payment->flags['amount_mismatch'] ?? false)->toBeTrue();

    Event::assertDispatched(PaymentAmountMismatch::class, fn (PaymentAmountMismatch $e) => $e->expectedMinor === 50000 && $e->receivedMinor === 40000);
});

it('does not treat a failed callback as an amount mismatch', function () {
    Event::fake([PaymentAmountMismatch::class]);

    $payment = Payment::create([
        'provider' => PaymentProvider::Mpesa,
        'category' => PaymentProvider::Mpesa->category(),
        'reference' => 'TX_FAILED_CB',
        'provider_reference' => 'ws_CO_FAILED_CB',
        'status' => PaymentStatus::Pending,
        'amount_minor' => 50000,
    ]);

    $callback = ['Body' => ['stkCallback' => ['CheckoutRequestID' => 'ws_CO_FAILED_CB', 'ResultCode' => 1, 'ResultDesc' => 'Insufficient funds']]];
    $webhook = Webhook::create(['provider' => 'mpesa', 'status' => WebhookStatus::Received, 'payload' => $callback]);

    runWebhookJob($webhook, $callback);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed);

    Event::assertNotDispatched(PaymentAmountMismatch::class);
});

it('marks the webhook failed with the error when the job exhausts its retries', function () {
    $webhook = Webhook::create(['provider' => 'mpesa', 'status' => WebhookStatus::Received, 'payload' => []]);

    (new DispatchWebhookJob($webhook, [], '{}'))->failed(new RuntimeException('callback exploded'));

    $webhook->refresh();
    expect($webhook->status)->toBe(WebhookStatus::Failed)
        ->and($webhook->error_message)->toBe('callback exploded');
});

it('accepts M-Pesa callbacks on an opaque alias route containing digits', function () {
    Bus::fake();
    config()->set('paysasa.webhook_aliases', ['k3j9x2a7' => 'mpesa']);

    $this->postJson('/paysasa/webhooks/k3j9x2a7', stkCallback('ws_CO_ALIAS', 100))->assertStatus(200);

    // Stored against the real provider, not the secret slug.
    expect(Webhook::where('provider', 'mpesa')->exists())->toBeTrue();

    Bus::assertDispatched(DispatchWebhookJob::class);
});
