<?php

declare(strict_types=1);

namespace Paysasa\Payments\Webhooks\Verifiers;

use Paysasa\Payments\Contracts\SignatureVerifier;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Exceptions\WebhookVerificationException;

/**
 * Reusable HMAC-SHA256-over-raw-body verifier for providers that follow
 * the common "shared secret + signature header" convention (Airtel Money,
 * T-Kash, and most bank/PesaLink aggregator webhooks). Configure the
 * header name and secret per provider when constructing.
 */
class HmacHeaderSignatureVerifier implements SignatureVerifier
{
    public function __construct(
        protected string $provider,
        protected string $secret,
        protected string $header = 'x-signature',
        protected string $algorithm = 'sha256',
    ) {
    }

    public function verify(WebhookPayload $payload): void
    {
        $signature = $payload->headers[$this->header][0] ?? null;

        if ($signature === null) {
            throw WebhookVerificationException::missingSignatureHeader($this->provider, $this->header);
        }

        $expected = hash_hmac($this->algorithm, $payload->rawBody, $this->secret);

        if (! hash_equals($expected, $signature)) {
            throw WebhookVerificationException::invalidSignature($this->provider);
        }
    }
}
