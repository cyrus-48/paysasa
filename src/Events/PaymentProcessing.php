<?php

declare(strict_types=1);

namespace Paysasa\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Paysasa\Payments\Models\Payment;

/** Fired immediately before the provider API call is made. */
class PaymentProcessing
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Payment $payment)
    {
    }
}
