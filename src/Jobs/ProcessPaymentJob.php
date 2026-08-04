<?php

declare(strict_types=1);

namespace Paysasa\Payments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Paysasa\Payments\Actions\InitiatePayment;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\Managers\PaymentManager;

/**
 * Dispatch a charge asynchronously instead of blocking the request cycle
 * on a provider round-trip — useful for high-volume batch billing (e.g.
 * charging a class of 500 students' saved payment methods overnight).
 * Synchronous callers should use the fluent API directly
 * (Payment::driver(...)->charge()); this job exists for the async path.
 */
class ProcessPaymentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public array $backoff;

    public function __construct(
        public readonly string $driverName,
        public readonly ChargeRequest $request,
    ) {
        $this->tries = config('paysasa.queue.tries', 5);
        $this->backoff = config('paysasa.queue.backoff', [10, 30, 60, 300, 900]);
        $this->onQueue(config('paysasa.queue.queue', 'payments'));
        $this->onConnection(config('paysasa.queue.connection'));
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(config('paysasa.queue.retry_until_minutes', 60));
    }

    public function handle(PaymentManager $manager, InitiatePayment $action): void
    {
        $driver = $manager->driverInstance($this->driverName);

        $action->execute($driver, $this->request);
    }
}
