<?php

declare(strict_types=1);

namespace Paysasa\Payments\Contracts;

use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\DTOs\WebhookPayload;

interface WebhookHandler
{
    /**
     * Translate a verified inbound webhook/callback into a PaymentResponse.
     * Called only after SignatureVerifier::verify() has passed — drivers
     * must not re-trust an unverified payload.
     */
    public function handleCallback(WebhookPayload $payload): PaymentResponse;
}
