<?php

declare(strict_types=1);

namespace Paysasa\Payments\DTOs;

final class RefundRequest
{
    public function __construct(
        public readonly string $transactionId,       // our internal UUID being refunded
        public readonly ?string $providerReference,   // provider's original reference
        public readonly ?float $amount = null,        // null = full refund
        public readonly ?string $reason = null,
        public readonly array $metadata = [],
    ) {
    }
}
