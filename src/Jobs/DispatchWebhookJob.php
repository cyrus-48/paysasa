<?php

declare(strict_types=1);

namespace Paysasa\Payments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Paysasa\Payments\Contracts\PaymentRepository;
use Paysasa\Payments\Contracts\WebhookHandler;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Enums\WebhookStatus;
use Paysasa\Payments\Events\WebhookProcessed;
use Paysasa\Payments\Managers\PaymentManager;
use Paysasa\Payments\Models\Webhook;

/**
 * Processes an already-verified, already-persisted webhook off the
 * request/response cycle so the provider gets a fast 200 OK (most
 * providers retry aggressively — and sometimes disable your endpoint —
 * if you're slow to acknowledge) while the actual status update, event
 * dispatch, and any listener side effects (emails, notifications) happen
 * in the background.
 */
class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public array $backoff;

    public function __construct(public readonly Webhook $webhook, public readonly array $payloadHeaders, public readonly string $rawBody)
    {
        $this->tries = config('paysasa.queue.tries', 5);
        $this->backoff = config('paysasa.queue.backoff', [10, 30, 60, 300, 900]);
        $this->onQueue(config('paysasa.queue.webhooks_queue', 'payment-webhooks'));
        $this->onConnection(config('paysasa.queue.connection'));
    }

    public function handle(PaymentManager $manager, PaymentRepository $repository): void
    {
        $driver = $manager->driverInstance($this->webhook->provider);

        if (! $driver instanceof WebhookHandler) {
            $this->webhook->update(['status' => WebhookStatus::Failed, 'error_message' => 'Driver does not implement WebhookHandler']);

            return;
        }

        $payload = new WebhookPayload(
            provider: $driver->provider(),
            headers: $this->payloadHeaders,
            rawBody: $this->rawBody,
            parsedBody: $this->webhook->payload,
        );

        $response = $driver->handleCallback($payload);

        $payment = $this->webhook->payment
            ?? ($response->providerReference ? $repository->findByProviderReference($response->providerReference) : null);

        if ($payment !== null) {
            $repository->updateFromResponse($payment, $response);
            $this->webhook->payment_id ??= $payment->id;
        }

        $this->webhook->update([
            'status' => WebhookStatus::Processed,
            'processed_at' => now(),
        ]);

        event(new WebhookProcessed($this->webhook, $response));
    }
}
