<?php

declare(strict_types=1);

namespace Paysasa\Payments\Webhooks\Verifiers;

use Paysasa\Payments\Contracts\SignatureVerifier;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Exceptions\WebhookVerificationException;

/**
 * Verifies the `Stripe-Signature` header: t=<timestamp>,v1=<hmac>, where
 * hmac = HMAC-SHA256("{timestamp}.{raw_body}", webhook_secret). Also
 * rejects timestamps older than 5 minutes to close the replay-attack
 * window, per Stripe's own recommendation.
 */
class StripeSignatureVerifier implements SignatureVerifier
{
    public function __construct(protected string $webhookSecret)
    {
    }

    public function verify(WebhookPayload $payload): void
    {
        if ($this->webhookSecret === '') {
            throw WebhookVerificationException::missingSecret('stripe');
        }

        $header = $payload->headers['stripe-signature'][0] ?? $payload->headers['Stripe-Signature'][0] ?? null;

        if ($header === null) {
            throw WebhookVerificationException::missingSignatureHeader('stripe', 'Stripe-Signature');
        }

        // The header can carry several v1 signatures while a webhook secret is
        // being rolled, so collect them all (parse_str would keep only the last).
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key === 't' && $value !== null) {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== null && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw WebhookVerificationException::invalidSignature('stripe');
        }

        if (abs(time() - (int) $timestamp) > 300) {
            throw WebhookVerificationException::invalidSignature('stripe');
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload->rawBody}", $this->webhookSecret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw WebhookVerificationException::invalidSignature('stripe');
    }
}
