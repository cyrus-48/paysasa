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
        $header = $payload->headers['stripe-signature'][0] ?? $payload->headers['Stripe-Signature'][0] ?? null;

        if ($header === null) {
            throw WebhookVerificationException::missingSignatureHeader('stripe', 'Stripe-Signature');
        }

        parse_str(str_replace(',', '&', $header), $parts);
        $timestamp = $parts['t'] ?? null;
        $signature = $parts['v1'] ?? null;

        if ($timestamp === null || $signature === null) {
            throw WebhookVerificationException::invalidSignature('stripe');
        }

        if (abs(time() - (int) $timestamp) > 300) {
            throw WebhookVerificationException::invalidSignature('stripe');
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload->rawBody}", $this->webhookSecret);

        if (! hash_equals($expected, $signature)) {
            throw WebhookVerificationException::invalidSignature('stripe');
        }
    }
}
