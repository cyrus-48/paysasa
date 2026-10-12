<?php

declare(strict_types=1);

namespace Paysasa\Payments\DTOs;

use Paysasa\Payments\Models\Payment;

final readonly class TransitionResult
{
    private function __construct(
        public Payment $payment,
        public bool $changed,
        public bool $conflict,
    ) {
    }

    public static function changed(Payment $payment): self
    {
        return new self($payment, true, false);
    }

    public static function unchanged(Payment $payment): self
    {
        return new self($payment, false, false);
    }

    public static function conflict(Payment $payment): self
    {
        return new self($payment, false, true);
    }
}
