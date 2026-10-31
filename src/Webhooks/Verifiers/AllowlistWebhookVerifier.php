<?php

declare(strict_types=1);

namespace Paysasa\Payments\Webhooks\Verifiers;

use Paysasa\Payments\Contracts\SignatureVerifier;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Exceptions\WebhookVerificationException;

/**
 * For providers whose callbacks carry no signature (Daraja, Airtel Money).
 *
 * Authenticity cannot be proven cryptographically, so the trust model is:
 *   1. an optional source-IP allowlist, and/or a secret (opaque) route alias;
 *   2. in production, fail closed when neither is configured;
 *   3. the driver confirms a claimed success with the provider before it
 *      settles anything (Contracts\ConfirmsCallbacks) — the layer that holds
 *      even if (1) is bypassed.
 */
class AllowlistWebhookVerifier implements SignatureVerifier
{
    public function __construct(
        protected string $provider,
        protected array $ipAllowlist = [],
        protected bool $isOpaqueRoute = false,
        protected ?string $environment = null,
    ) {
    }

    public function verify(WebhookPayload $payload): void
    {
        $env = $this->environment ?? app()->environment();

        if ($env === 'production' && $this->ipAllowlist === [] && ! $this->isOpaqueRoute) {
            throw WebhookVerificationException::misconfigured($this->provider);
        }

        if ($this->ipAllowlist === []) {
            return;
        }

        $ip = request()?->ip();

        if ($ip === null || ! in_array($ip, $this->ipAllowlist, true)) {
            throw WebhookVerificationException::invalidSignature($this->provider);
        }
    }
}
