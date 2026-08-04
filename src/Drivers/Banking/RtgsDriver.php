<?php

declare(strict_types=1);

namespace Paysasa\Payments\Drivers\Banking;

use Illuminate\Support\Carbon;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;

/**
 * Real-Time Gross Settlement — for high-value transfers, same-day but
 * subject to the Central Bank of Kenya's RTGS settlement window
 * (typically closes mid-afternoon on business days). Requests submitted
 * after the cutoff are queued for the next business day rather than
 * rejected outright, mirroring how banks actually handle this rail.
 */
class RtgsDriver extends AbstractBankingDriver
{
    public function provider(): PaymentProvider
    {
        return PaymentProvider::Rtgs;
    }

    protected function endpointPrefix(): string
    {
        return '/rtgs/v1';
    }

    protected function cutoffTime(): string
    {
        return $this->config['cutoff_time'] ?? '15:00';
    }

    protected function isWithinSettlementWindow(): bool
    {
        $now = Carbon::now();

        return ! $now->isWeekend() && $now->format('H:i') <= $this->cutoffTime();
    }

    public function transfer(ChargeRequest $request): PaymentResponse
    {
        if (! $this->isWithinSettlementWindow()) {
            return new PaymentResponse(
                $this->provider(),
                PaymentStatus::Pending,
                message: "Submitted after today's RTGS cutoff ({$this->cutoffTime()}); queued for next-business-day settlement.",
                metadata: ['queued_for' => Carbon::now()->addWeekday()->toDateString()],
            );
        }

        return parent::transfer($request);
    }
}
