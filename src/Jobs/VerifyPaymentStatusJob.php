<?php

declare(strict_types=1);

namespace Paysasa\Payments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Paysasa\Payments\Actions\TransitionPayment;
use Paysasa\Payments\Actions\VerifyPayment;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Exceptions\ProviderApiException;
use Paysasa\Payments\Managers\PaymentManager;
use Paysasa\Payments\Models\Payment;

/**
 * Single-shot status verification job for a payment still Pending/Processing.
 * Dispatched with an initial delay on charge creation, or scheduled
 * periodically by the `paysasa:reconcile` sweep.
 */
class VerifyPaymentStatusJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly Payment $payment,
        public readonly string $driverName,
    ) {
        $this->onQueue(config('paysasa.queue.queue', 'payments'));
        $this->onConnection(config('paysasa.queue.connection'));
    }

    public function uniqueId(): string
    {
        return (string) $this->payment->uuid;
    }

    public function handle(PaymentManager $manager, VerifyPayment $action, TransitionPayment $transition): void
    {
        $this->payment->refresh();

        if ($this->payment->status->isTerminal()) {
            return;
        }

        $this->payment->increment('check_count');
        $this->payment->update(['last_checked_at' => now()]);

        // Check if payment has expired
        if ($this->payment->expires_at && now()->greaterThan($this->payment->expires_at)) {
            $expiredResponse = new PaymentResponse(
                provider: $this->payment->provider,
                status: PaymentStatus::Expired,
                transactionId: (string) $this->payment->uuid,
                message: 'Payment expired after settlement TTL elapsed.',
            );

            $transition($this->payment, $expiredResponse, 'reconcile');

            return;
        }

        $driver = $manager->driverInstance($this->driverName);

        try {
            $response = $action->execute($driver, $this->payment);
        } catch (ProviderApiException) {
            // Transient error or "still processing" response from provider
            return;
        }

        if (! $response->status->isTerminal() && $this->payment->expires_at && now()->greaterThan($this->payment->expires_at)) {
            $expiredResponse = new PaymentResponse(
                provider: $this->payment->provider,
                status: PaymentStatus::Expired,
                transactionId: (string) $this->payment->uuid,
                message: 'Payment expired after settlement TTL elapsed.',
            );

            $transition($this->payment, $expiredResponse, 'reconcile');
        }
    }
}
