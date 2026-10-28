<?php

declare(strict_types=1);

namespace Paysasa\Payments\Contracts;

use Paysasa\Payments\DTOs\WebhookPayload;

/**
 * Implemented by drivers whose provider posts many event types (transfers,
 * refunds, disputes, ...) to the single webhook URL. Events a driver does not
 * handle are recorded as `ignored` instead of being parsed as a payment
 * callback — which would fail, retry, and eventually mark the webhook failed.
 */
interface FiltersWebhookEvents
{
    /** Whether handleCallback() should be called for this (already verified) webhook. */
    public function handlesWebhook(WebhookPayload $payload): bool;
}
