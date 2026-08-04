<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Paysasa\Payments\Enums\WebhookStatus;
use Paysasa\Payments\Jobs\DispatchWebhookJob;
use Paysasa\Payments\Models\Webhook;

it('rejects a webhook with an invalid Stripe signature and records the failure', function () {
    $response = $this->postJson('/paysasa/webhooks/stripe', ['type' => 'payment_intent.succeeded'], [
        'Stripe-Signature' => 't='.time().',v1=invalid',
    ]);

    $response->assertStatus(401);

    expect(Webhook::query()->where('provider', 'stripe')->where('status', WebhookStatus::VerificationFailed->value)->exists())->toBeTrue();
});

it('accepts a validly-signed Stripe webhook, persists it, and queues processing', function () {
    Bus::fake();

    $secret = config('paysasa.drivers.stripe.webhook_secret');
    $body = ['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_123', 'status' => 'succeeded']]];
    $rawBody = json_encode($body);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

    $response = $this->call('POST', '/paysasa/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_Stripe-Signature' => "t={$timestamp},v1={$signature}",
    ], $rawBody);

    $response->assertStatus(200);

    expect(Webhook::query()->where('provider', 'stripe')->where('status', WebhookStatus::Received->value)->exists())->toBeTrue();

    Bus::assertDispatched(DispatchWebhookJob::class);
});

it('returns 404 for an unknown provider', function () {
    $this->postJson('/paysasa/webhooks/not-a-real-provider', [])->assertStatus(404);
});
