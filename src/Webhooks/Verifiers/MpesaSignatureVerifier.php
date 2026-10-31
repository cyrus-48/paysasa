<?php

declare(strict_types=1);

namespace Paysasa\Payments\Webhooks\Verifiers;

/**
 * Daraja does not HMAC-sign STK/C2B/B2C callbacks.
 * In production, it fails closed if the allowlist is empty unless an opaque alias is used.
 */
class MpesaSignatureVerifier extends AllowlistWebhookVerifier
{
    public function __construct(
        array $ipAllowlist = [],
        bool $isOpaqueRoute = false,
        ?string $environment = null,
    ) {
        parent::__construct('mpesa', $ipAllowlist, $isOpaqueRoute, $environment);
    }
}
