<?php

declare(strict_types=1);

namespace Paysasa\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Models\Webhook;

/** Fired after the driver has translated the webhook into a PaymentResponse and the payment record has been updated. */
class WebhookProcessed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Webhook $webhook,
        public readonly PaymentResponse $response,
    ) {
    }
}
