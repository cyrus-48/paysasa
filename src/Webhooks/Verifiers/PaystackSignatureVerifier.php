<?php

declare(strict_types=1);

namespace Paysasa\Payments\Webhooks\Verifiers;

use Paysasa\Payments\Contracts\SignatureVerifier;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Exceptions\WebhookVerificationException;

/** Verifies `x-paystack-signature`: HMAC-SHA512 of the raw body using the secret key. */
class PaystackSignatureVerifier implements SignatureVerifier
{
    public function __construct(protected string $secretKey)
    {
    }

    public function verify(WebhookPayload $payload): void
    {
        $signature = $payload->headers['x-paystack-signature'][0] ?? null;

        if ($signature === null) {
            throw WebhookVerificationException::missingSignatureHeader('paystack', 'x-paystack-signature');
        }

        $expected = hash_hmac('sha512', $payload->rawBody, $this->secretKey);

        if (! hash_equals($expected, $signature)) {
            throw WebhookVerificationException::invalidSignature('paystack');
        }
    }
}
