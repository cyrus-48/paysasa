<?php

declare(strict_types=1);

namespace Paysasa\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Models\Payment;

/** Fired when a payment link / checkout session / STK prompt times out unanswered. */
class PaymentExpired
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Payment $payment,
        public readonly ?PaymentResponse $response = null,
    ) {
    }
}
