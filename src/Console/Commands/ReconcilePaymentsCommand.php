<?php

declare(strict_types=1);

namespace Paysasa\Payments\Console\Commands;

use Illuminate\Console\Command;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Jobs\VerifyPaymentStatusJob;
use Paysasa\Payments\Models\Payment;

class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'paysasa:reconcile';

    protected $description = 'Sweep pending payments and dispatch verification jobs';

    public function handle(): int
    {
        $this->info('Starting Paysasa payment reconciliation sweep...');

        Payment::query()
            ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Processing])
            ->where(function ($query) {
                $query->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<=', now()->subMinutes(1));
            })
            ->chunkById(100, function ($payments) {
                foreach ($payments as $payment) {
                    if (! $this->isDue($payment)) {
                        continue;
                    }

                    VerifyPaymentStatusJob::dispatch($payment, $payment->provider->value);
                }
            });

        $this->info('Reconciliation jobs dispatched.');

        return self::SUCCESS;
    }

    /**
     * Back off as a payment keeps coming back unresolved so a long-pending
     * payment doesn't hit the provider's status API every minute: every
     * minute for the first few checks, then every 5 minutes, then every 15.
     */
    private function isDue(Payment $payment): bool
    {
        if ($payment->last_checked_at === null) {
            return true;
        }

        $intervalMinutes = match (true) {
            $payment->check_count < 5 => 1,
            $payment->check_count < 15 => 5,
            default => 15,
        };

        return $payment->last_checked_at->lte(now()->subMinutes($intervalMinutes));
    }
}
