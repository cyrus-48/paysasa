<?php

declare(strict_types=1);

namespace Paysasa\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Models\Payment;

/** Fired when the payer explicitly cancels (e.g. cancels the STK push prompt), distinct from a provider-side Failed. */
class PaymentCancelled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Payment $payment,
        public readonly ?PaymentResponse $response = null,
    ) {
    }
}
