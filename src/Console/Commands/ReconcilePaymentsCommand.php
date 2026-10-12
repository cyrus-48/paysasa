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
                    VerifyPaymentStatusJob::dispatch($payment, $payment->provider->value);
                }
            });

        $this->info('Reconciliation jobs dispatched.');

        return self::SUCCESS;
    }
}
