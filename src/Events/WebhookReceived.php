<?php

declare(strict_types=1);

namespace Paysasa\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Models\Webhook;

/** Fired immediately after signature verification succeeds, before the driver translates the payload. */
class WebhookReceived
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Webhook $webhook,
        public readonly WebhookPayload $payload,
    ) {
    }
}
