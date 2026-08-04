<?php

declare(strict_types=1);

namespace Paysasa\Payments\DTOs;

use Paysasa\Payments\Enums\PaymentProvider;

final class BalanceResponse
{
    public function __construct(
        public readonly PaymentProvider $provider,
        public readonly float $available,
        public readonly ?float $reserved = null,
        public readonly ?string $currency = 'KES',
        public readonly mixed $rawResponse = null,
    ) {
    }
}
