<?php

declare(strict_types=1);

namespace Paysasa\Payments\Webhooks\Verifiers;

use Paysasa\Payments\Contracts\SignatureVerifier;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Exceptions\WebhookVerificationException;

/**
 * Daraja does not HMAC-sign STK/C2B/B2C callbacks.
 * In production, it fails closed if the allowlist is empty unless an opaque alias is used.
 */
class MpesaSignatureVerifier implements SignatureVerifier
{
    public function __construct(
        protected array $ipAllowlist = [],
        protected bool $isOpaqueRoute = false,
        protected ?string $environment = null,
    ) {
    }

    public function verify(WebhookPayload $payload): void
    {
        $env = $this->environment ?? app()->environment();

        if ($env === 'production' && $this->ipAllowlist === [] && ! $this->isOpaqueRoute) {
            throw WebhookVerificationException::misconfigured('mpesa');
        }

        if ($this->ipAllowlist === []) {
            return;
        }

        $ip = request()?->ip();

        if ($ip === null || ! in_array($ip, $this->ipAllowlist, true)) {
            throw WebhookVerificationException::invalidSignature('mpesa');
        }
    }
}
