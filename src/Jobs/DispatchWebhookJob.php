<?php

declare(strict_types=1);

namespace Paysasa\Payments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Paysasa\Payments\Actions\TransitionPayment;
use Paysasa\Payments\Contracts\ConfirmsCallbacks;
use Paysasa\Payments\Contracts\PaymentRepository;
use Paysasa\Payments\Contracts\WebhookHandler;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Enums\Currency;
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

        // Unsigned callbacks (Daraja) are never trusted on their own: a claimed
        // success must be confirmed with the provider before it settles anything.
        if ($driver instanceof ConfirmsCallbacks && $driver->shouldConfirmCallback($response)) {
            // A transport/API error here propagates: the queue retries the job
            // with backoff, and failed() records the webhook if it never succeeds.
            $confirmed = $driver->verify((string) $response->providerReference);

            if ($confirmed->status !== PaymentStatus::Successful) {
                if (! $confirmed->status->isTerminal()) {
                    // The provider cannot confirm yet (still processing): try again
                    // shortly, and leave it to the reconcile sweep if it never does.
                    if ($this->attempts() < $this->tries) {
                        $this->release($this->backoff[$this->attempts() - 1] ?? 60);

                        return;
                    }

                    $this->webhook->update([
                        'status' => WebhookStatus::Failed,
                        'error_message' => 'Success callback could not be confirmed with the provider.',
                    ]);

                    return;
                }

                // The provider says the payment did not succeed although the
                // callback claimed it did: the provider is the authority.
                $this->webhook->error_message = mb_substr(
                    "Callback claimed success but the provider reports [{$confirmed->status->value}].",
                    0,
                    500,
                );
                $response = $confirmed;
            }
        }

        // Amount reconciliation: a success callback that reports a different
        // amount than we charged must not settle the payment. Compare in minor
        // units so float rounding can never produce a false mismatch.
        if ($response->status === PaymentStatus::Successful && $response->amount !== null) {
            $currency = Currency::tryFrom((string) $payment->currency) ?? Currency::KES;
            $expectedMinor = (int) $payment->amount_minor;
            $receivedMinor = $currency->toMinorUnits($response->amount);

            if ($expectedMinor !== $receivedMinor) {
                $this->holdForAmountReview($payment, $expectedMinor, $receivedMinor);

                $this->webhook->update([
                    'status' => WebhookStatus::Processed,
                    'processed_at' => now(),
                ]);

                event(new WebhookProcessed($this->webhook, $response));

                return;
            }
        }

        $transition($payment, $response, 'webhook');

        $this->webhook->update([
            'status' => WebhookStatus::Processed,
            'processed_at' => now(),
        ]);

        event(new WebhookProcessed($this->webhook, $response));
    }

    /**
     * Flag the payment for manual review and hold it in Processing.
     *
     * This runs under a row lock and never moves a payment out of a terminal
     * state: a late, mismatching callback for an already-Successful (or
     * Failed/Cancelled/...) payment is flagged and reported, but its status
     * is left alone. Only a payment still awaiting its outcome is held.
     */
    private function holdForAmountReview(Payment $payment, int $expectedMinor, int $receivedMinor): void
    {
        DB::transaction(function () use ($payment) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $flags = $locked->flags ?? [];
            $flags['amount_mismatch'] = true;
            $locked->flags = $flags;

            if ($locked->status === PaymentStatus::Pending) {
                $locked->status = PaymentStatus::Processing;
            }

            $locked->save();
        });

        event(new PaymentAmountMismatch($payment->refresh(), $expectedMinor, $receivedMinor));
    }

    /**
     * Called by the queue once all retries are exhausted. Without this a
     * webhook whose processing keeps throwing would sit in `received` forever
     * with no trace of why.
     */
    public function failed(\Throwable $exception): void
    {
        $this->webhook->update([
            'status' => WebhookStatus::Failed,
            'error_message' => mb_substr($exception->getMessage(), 0, 500),
        ]);
    }
}
