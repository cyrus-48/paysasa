<?php

declare(strict_types=1);

namespace Paysasa\Payments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Paysasa\Payments\Actions\VerifyPayment;
use Paysasa\Payments\Managers\PaymentManager;
use Paysasa\Payments\Models\Payment;

/**
 * Safety net for payments still Pending/Processing after their provider's
 * expected settlement window (STK push not answered, webhook lost in
 * transit, etc). Dispatch with a delay from the driver or from
 * PaymentInitiated listener, e.g.:
 *
 *   VerifyPaymentStatusJob::dispatch($payment, 'mpesa')->delay(now()->addSeconds(45));
 *
 * Re-queues itself with backoff, up to $maxChecks times, until the
 * payment reaches a terminal PaymentStatus.
 */
class VerifyPaymentStatusJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1; // re-queuing is handled manually via checkNumber, not queue-level retries

    public function __construct(
        public readonly Payment $payment,
        public readonly string $driverName,
        public readonly int $checkNumber = 1,
        public readonly int $maxChecks = 5,
    ) {
        $this->onQueue(config('paysasa.queue.queue', 'payments'));
        $this->onConnection(config('paysasa.queue.connection'));
    }

    public function handle(PaymentManager $manager, VerifyPayment $action): void
    {
        $this->payment->refresh();

        if ($this->payment->status->isTerminal()) {
            return;
        }

        $driver = $manager->driverInstance($this->driverName);
        $response = $action->execute($driver, $this->payment);

        if (! $response->status->isTerminal() && $this->checkNumber < $this->maxChecks) {
            $delaySeconds = config('paysasa.queue.backoff', [10, 30, 60, 300, 900])[$this->checkNumber - 1] ?? 900;

            static::dispatch($this->payment, $this->driverName, $this->checkNumber + 1, $this->maxChecks)
                ->delay(now()->addSeconds($delaySeconds));
        }
    }
}
