<?php

declare(strict_types=1);

use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Exceptions\WebhookVerificationException;
use Paysasa\Payments\Webhooks\Verifiers\FlutterwaveSignatureVerifier;
use Paysasa\Payments\Webhooks\Verifiers\HmacHeaderSignatureVerifier;
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

// --- Fail closed when the signing secret is not configured ------------------
// hash_hmac() happily accepts an empty key, so without an explicit guard an
// attacker can sign a forged webhook with '' and have it verify.

it('rejects a Stripe webhook signed with an empty key when no secret is configured', function () {
    $body = '{"type":"payment_intent.succeeded"}';
    $timestamp = time();
    $forged = hash_hmac('sha256', "{$timestamp}.{$body}", '');

    $payload = new WebhookPayload(
        PaymentProvider::Stripe,
        ['stripe-signature' => ["t={$timestamp},v1={$forged}"]],
        $body,
        [],
    );

    (new StripeSignatureVerifier(''))->verify($payload);
})->throws(WebhookVerificationException::class, 'no signing secret');

it('rejects a Paystack webhook signed with an empty key when no secret is configured', function () {
    $body = '{"event":"charge.success"}';
    $forged = hash_hmac('sha512', $body, '');

    $payload = new WebhookPayload(PaymentProvider::Paystack, ['x-paystack-signature' => [$forged]], $body, []);

    (new PaystackSignatureVerifier(''))->verify($payload);
})->throws(WebhookVerificationException::class, 'no signing secret');

it('rejects a Flutterwave webhook with an empty verif-hash when no secret hash is configured', function () {
    $payload = new WebhookPayload(PaymentProvider::Flutterwave, ['verif-hash' => ['']], '{}', []);

    (new FlutterwaveSignatureVerifier(''))->verify($payload);
})->throws(WebhookVerificationException::class, 'no signing secret');

it('rejects an HMAC-header webhook signed with an empty key when no secret is configured', function () {
    $body = '{"transaction":{"id":"1"}}';
    $forged = hash_hmac('sha256', $body, '');

    $payload = new WebhookPayload(PaymentProvider::Airtel, ['x-signature' => [$forged]], $body, []);

    (new HmacHeaderSignatureVerifier('airtel', ''))->verify($payload);
})->throws(WebhookVerificationException::class, 'no signing secret');

it('accepts a valid HMAC-header webhook when the secret is configured', function () {
    $body = '{"transaction":{"id":"1"}}';
    $signature = hash_hmac('sha256', $body, 'airtel-secret');

    $payload = new WebhookPayload(PaymentProvider::Airtel, ['x-signature' => [$signature]], $body, []);

    (new HmacHeaderSignatureVerifier('airtel', 'airtel-secret'))->verify($payload);
})->throwsNoExceptions();

it('accepts a Stripe webhook when any one of several v1 signatures matches (secret rotation)', function () {
    $secret = 'whsec_new';
    $body = '{"type":"payment_intent.succeeded"}';
    $timestamp = time();
    $old = hash_hmac('sha256', "{$timestamp}.{$body}", 'whsec_old');
    $new = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    $payload = new WebhookPayload(
        PaymentProvider::Stripe,
        ['stripe-signature' => ["t={$timestamp},v1={$old},v1={$new}"]],
        $body,
        [],
    );

    (new StripeSignatureVerifier($secret))->verify($payload);
})->throwsNoExceptions();

it('rejects a Stripe webhook older than the replay window even with a valid signature', function () {
    $secret = 'whsec_test';
    $body = '{}';
    $timestamp = time() - 1000;
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    $payload = new WebhookPayload(PaymentProvider::Stripe, ['stripe-signature' => ["t={$timestamp},v1={$signature}"]], $body, []);

    (new StripeSignatureVerifier($secret))->verify($payload);
})->throws(WebhookVerificationException::class);
