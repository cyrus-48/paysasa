<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Enums\WebhookStatus;
use Paysasa\Payments\Events\PaymentAmountMismatch;
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
