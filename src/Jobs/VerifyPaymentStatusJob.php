<?php

declare(strict_types=1);

namespace Paysasa\Payments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
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

        $driver = $manager->driverInstance($this->driverName);

        // Always ask the provider first. Expiry is only a fallback for "still
        // no answer": expiring on the clock alone would mark a customer who
        // paid just before the deadline (callback delayed or lost) as Expired.
        $response = null;

        try {
            $response = $action->execute($driver, $this->payment);
        } catch (ProviderApiException|ConnectionException) {
            // Transient provider/network error. We could not confirm the
            // outcome this round; the sweep will try again, or expire the
            // payment below if its window has already closed.
        }

        if ($response !== null && $response->status->isTerminal()) {
            return;
        }

        $this->expireIfWindowClosed($transition);
    }

    private function expireIfWindowClosed(TransitionPayment $transition): void
    {
        // The provider call (or a webhook racing it) may have settled the
        // payment since we loaded it.
        $this->payment->refresh();

        if ($this->payment->status->isTerminal()) {
            return;
        }

        if ($this->payment->expires_at === null || ! now()->greaterThan($this->payment->expires_at)) {
            return;
        }

        $transition(
            $this->payment,
            new PaymentResponse(
                provider: $this->payment->provider,
                status: PaymentStatus::Expired,
                transactionId: (string) $this->payment->uuid,
                message: 'Payment expired after settlement TTL elapsed.',
            ),
            'reconcile',
        );
    }
}
