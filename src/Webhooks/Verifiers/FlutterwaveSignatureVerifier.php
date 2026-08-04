<?php

declare(strict_types=1);

namespace Paysasa\Payments\Webhooks\Verifiers;

use Paysasa\Payments\Contracts\SignatureVerifier;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Exceptions\WebhookVerificationException;

/**
 * Flutterwave doesn't HMAC-sign webhooks; instead it echoes back a static
 * secret hash you configured in your dashboard via the `verif-hash`
 * header. Verification is therefore a constant-time equality check
 * against config('paysasa.drivers.flutterwave.webhook_secret_hash').
 */
class FlutterwaveSignatureVerifier implements SignatureVerifier
{
    public function __construct(protected string $secretHash)
    {
    }

    public function verify(WebhookPayload $payload): void
    {
        $hash = $payload->headers['verif-hash'][0] ?? null;

        if ($hash === null) {
            throw WebhookVerificationException::missingSignatureHeader('flutterwave', 'verif-hash');
        }

        if (! hash_equals($this->secretHash, $hash)) {
            throw WebhookVerificationException::invalidSignature('flutterwave');
        }
    }
}
