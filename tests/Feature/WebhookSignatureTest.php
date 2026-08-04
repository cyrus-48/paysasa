<?php

declare(strict_types=1);

use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Exceptions\WebhookVerificationException;
use Paysasa\Payments\Webhooks\Verifiers\FlutterwaveSignatureVerifier;
use Paysasa\Payments\Webhooks\Verifiers\PaystackSignatureVerifier;
use Paysasa\Payments\Webhooks\Verifiers\StripeSignatureVerifier;

it('accepts a Stripe webhook with a valid HMAC signature', function () {
    $secret = 'whsec_test';
    $body = json_encode(['type' => 'payment_intent.succeeded']);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    $payload = new WebhookPayload(
        PaymentProvider::Stripe,
        ['stripe-signature' => ["t={$timestamp},v1={$signature}"]],
        $body,
        json_decode($body, true),
    );

    (new StripeSignatureVerifier($secret))->verify($payload);
})->throwsNoExceptions();

it('rejects a Stripe webhook with a tampered signature', function () {
    $payload = new WebhookPayload(
        PaymentProvider::Stripe,
        ['stripe-signature' => ['t='.time().',v1=deadbeef']],
        '{}',
        [],
    );

    (new StripeSignatureVerifier('whsec_test'))->verify($payload);
})->throws(WebhookVerificationException::class);

it('rejects a Stripe webhook missing the signature header entirely', function () {
    $payload = new WebhookPayload(PaymentProvider::Stripe, [], '{}', []);

    (new StripeSignatureVerifier('whsec_test'))->verify($payload);
})->throws(WebhookVerificationException::class);

it('accepts a Paystack webhook with a valid SHA512 signature', function () {
    $secret = 'sk_test_paystack';
    $body = json_encode(['event' => 'charge.success']);
    $signature = hash_hmac('sha512', $body, $secret);

    $payload = new WebhookPayload(PaymentProvider::Paystack, ['x-paystack-signature' => [$signature]], $body, json_decode($body, true));

    (new PaystackSignatureVerifier($secret))->verify($payload);
})->throwsNoExceptions();

it('accepts a Flutterwave webhook whose verif-hash matches the configured secret', function () {
    $payload = new WebhookPayload(PaymentProvider::Flutterwave, ['verif-hash' => ['my-secret-hash']], '{}', []);

    (new FlutterwaveSignatureVerifier('my-secret-hash'))->verify($payload);
})->throwsNoExceptions();

it('rejects a Flutterwave webhook with a mismatched verif-hash', function () {
    $payload = new WebhookPayload(PaymentProvider::Flutterwave, ['verif-hash' => ['wrong']], '{}', []);

    (new FlutterwaveSignatureVerifier('my-secret-hash'))->verify($payload);
})->throws(WebhookVerificationException::class);
