<?php

declare(strict_types=1);

namespace Paysasa\Payments\DTOs;

use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\RefundStatus;

final class RefundResponse
{
    public function __construct(
        public readonly PaymentProvider $provider,
        public readonly RefundStatus $status,
        public readonly ?string $refundId = null,
        public readonly ?string $providerReference = null,
        public readonly ?float $amount = null,
        public readonly ?string $message = null,
        public readonly mixed $rawResponse = null,
    ) {
    }

    public function successful(): bool
    {
        return $this->status === RefundStatus::Completed;
    }
}
