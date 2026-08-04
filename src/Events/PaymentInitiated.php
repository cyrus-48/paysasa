<?php

declare(strict_types=1);

namespace Paysasa\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\Models\Payment;

/** Fired the instant a Payment row is created, before the provider is ever called. */
class PaymentInitiated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Payment $payment,
        public readonly ChargeRequest $request,
    ) {
    }
}
