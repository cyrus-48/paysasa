<?php

declare(strict_types=1);

namespace Paysasa\Payments\Exceptions;

/**
 * Thrown when an inbound webhook fails signature/HMAC verification.
 * The webhook controller catches this, records a WebhookStatus::VerificationFailed
 * entry, and returns 401 without ever invoking the driver's callback handler.
 */
class WebhookVerificationException extends PaymentException
{
    public static function invalidSignature(string $provider): self
    {
        return new self("Webhook signature verification failed for provider [{$provider}].", [
            'provider' => $provider,
        ]);
    }

    public static function missingSignatureHeader(string $provider, string $header): self
    {
        return new self("Webhook from [{$provider}] is missing expected signature header [{$header}].", [
            'provider' => $provider,
            'header' => $header,
        ]);
    }

    /**
     * The provider's signing secret is not configured. HMAC with an empty key
     * is still a valid HMAC, so without this guard anyone could forge a
     * "valid" signature for an app that forgot to set the secret.
     */
    public static function missingSecret(string $provider): self
    {
        return new self("Webhook verification for [{$provider}] failed closed: no signing secret is configured.", [
            'provider' => $provider,
        ]);
    }

    public static function misconfigured(string $provider): self
    {
        return new self("Webhook verification for [{$provider}] is misconfigured in production (empty allowlist and non-opaque route).", [
            'provider' => $provider,
        ]);
    }
}
