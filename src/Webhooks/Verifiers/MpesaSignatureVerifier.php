<?php

declare(strict_types=1);

namespace Paysasa\Payments\Webhooks\Verifiers;

use Paysasa\Payments\Contracts\SignatureVerifier;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Exceptions\WebhookVerificationException;

/**
 * Daraja does not HMAC-sign STK/C2B/B2C callbacks — Safaricom's own
 * recommended mitigation is (a) keeping the callback URL secret/unguessable
 * and (b) restricting it to Safaricom's published IP ranges. This verifier
 * enforces an IP allowlist when
 * config('paysasa.drivers.mpesa.webhook_ip_allowlist') is populated; if left
 * empty (e.g. in sandbox, where Safaricom's sandbox source IPs are not
 * published) it passes through and relies on the callback URL being kept
 * secret. Populate the allowlist before going to production.
 */
class MpesaSignatureVerifier implements SignatureVerifier
{
    public function __construct(protected array $ipAllowlist = [])
    {
    }

    public function verify(WebhookPayload $payload): void
    {
        if ($this->ipAllowlist === []) {
            return;
        }

        $ip = request()?->ip();

        if ($ip === null || ! in_array($ip, $this->ipAllowlist, true)) {
            throw WebhookVerificationException::invalidSignature('mpesa');
        }
    }
}
