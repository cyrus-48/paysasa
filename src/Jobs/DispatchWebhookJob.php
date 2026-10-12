<?php

declare(strict_types=1);

namespace Paysasa\Payments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Paysasa\Payments\Actions\TransitionPayment;
use Paysasa\Payments\Contracts\PaymentRepository;
use Paysasa\Payments\Contracts\WebhookHandler;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Enums\WebhookStatus;
use Paysasa\Payments\Events\PaymentAmountMismatch;
use Paysasa\Payments\Events\WebhookProcessed;
use Paysasa\Payments\Events\WebhookUnmatched;
use Paysasa\Payments\Managers\PaymentManager;
use Paysasa\Payments\Models\Payment;
use Paysasa\Payments\Models\Webhook;

/**
 * Processes an already-verified, already-persisted webhook off the
 * request/response cycle.
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

    public function handle(PaymentManager $manager, PaymentRepository $repository, TransitionPayment $transition): void
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

        // Matching order:
        // 1. Attached payment
        // 2. By provider reference
        // 3. By reference / transaction UUID
        // 4. By MerchantRequestID in metadata
        $payment = $this->webhook->payment;

        if ($payment === null && $response->providerReference) {
            $payment = $repository->findByProviderReference($response->providerReference);
        }

        if ($payment === null && $response->transactionId) {
            $payment = Payment::query()->where('uuid', $response->transactionId)
                ->orWhere('reference', $response->transactionId)
                ->first();
        }

        if ($payment === null && isset($this->webhook->payload['Body']['stkCallback']['MerchantRequestID'])) {
            $merchantRequestId = $this->webhook->payload['Body']['stkCallback']['MerchantRequestID'];
            $payment = Payment::query()->whereJsonContains('metadata->MerchantRequestID', $merchantRequestId)->first();
        }

        if ($payment === null) {
            $this->webhook->increment('match_attempts');

            if ($this->attempts() < $this->tries) {
                $this->webhook->update(['status' => WebhookStatus::Unmatched]);
                $delay = $this->backoff[$this->attempts() - 1] ?? 60;
                $this->release($delay);

                return;
            }

            $this->webhook->update([
                'status' => WebhookStatus::Unmatched,
                'error_message' => 'Payment could not be matched after all retries',
            ]);

            event(new WebhookUnmatched($this->webhook));

            return;
        }

        assert($payment instanceof Payment);

        $this->webhook->payment_id ??= $payment->id;

        // Amount reconciliation check if amount is reported by callback
        if ($response->amount !== null && abs($response->amount - $payment->amount) > 0.001) {
            $flags = $payment->flags ?? [];
            $flags['amount_mismatch'] = true;
            $payment->update([
                'flags' => $flags,
                'status' => PaymentStatus::Processing,
            ]);

            $currency = $payment->currency ? \Paysasa\Payments\Enums\Currency::tryFrom($payment->currency) ?? \Paysasa\Payments\Enums\Currency::KES : \Paysasa\Payments\Enums\Currency::KES;
            event(new PaymentAmountMismatch(
                $payment,
                $currency->toMinorUnits((float) $payment->amount),
                $currency->toMinorUnits((float) $response->amount)
            ));

            $this->webhook->update([
                'status' => WebhookStatus::Processed,
                'processed_at' => now(),
            ]);

            event(new WebhookProcessed($this->webhook, $response));

            return;
        }

        $transition($payment, $response, 'webhook');

        $this->webhook->update([
            'status' => WebhookStatus::Processed,
            'processed_at' => now(),
        ]);

        event(new WebhookProcessed($this->webhook, $response));
    }
}
