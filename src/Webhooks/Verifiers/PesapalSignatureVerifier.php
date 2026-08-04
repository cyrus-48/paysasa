<?php

declare(strict_types=1);

namespace Paysasa\Payments\Webhooks\Verifiers;

use Paysasa\Payments\Contracts\SignatureVerifier;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Exceptions\WebhookVerificationException;

/**
 * Pesapal's IPN callback carries no signature — instead it supplies an
 * OrderTrackingId that the driver re-queries via GetTransactionStatus
 * server-to-server (see PesapalDriver::handleCallback()), which is itself
 * the authoritative check. This verifier's role is narrower: reject
 * requests that don't even carry the expected identifiers, before the
 * driver bothers making that API call.
 */
class PesapalSignatureVerifier implements SignatureVerifier
{
    public function verify(WebhookPayload $payload): void
    {
        if ($payload->get('OrderTrackingId') === null && $payload->get('orderTrackingId') === null) {
            throw WebhookVerificationException::missingSignatureHeader('pesapal', 'OrderTrackingId');
        }
    }
}
