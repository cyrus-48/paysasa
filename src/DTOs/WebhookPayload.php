<?php

declare(strict_types=1);

namespace Paysasa\Payments\DTOs;

use Paysasa\Payments\Enums\PaymentProvider;

/**
 * Normalized wrapper around an inbound webhook/callback request, produced
 * by the WebhookController before it's handed to the owning driver's
 * handleCallback(). Keeps raw headers/body available for signature
 * re-verification and audit storage.
 */
final class WebhookPayload
{
    public function __construct(
        public readonly PaymentProvider $provider,
        public readonly array $headers,
        public readonly string $rawBody,
        public readonly array $parsedBody,
        public readonly ?string $signature = null,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->parsedBody, $key, $default);
    }
}
