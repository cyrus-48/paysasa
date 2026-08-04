<?php

declare(strict_types=1);

namespace Paysasa\Payments\Contracts;

use Paysasa\Payments\DTOs\WebhookPayload;

/**
 * Strategy Pattern: one implementation per provider's signing scheme
 * (HMAC-SHA256 over raw body, RSA signature, IP allowlist, shared-secret
 * query param...). Registered in Webhooks\WebhookDispatcher and resolved
 * by provider name.
 */
interface SignatureVerifier
{
    /** @throws \Paysasa\Payments\Exceptions\WebhookVerificationException */
    public function verify(WebhookPayload $payload): void;
}
