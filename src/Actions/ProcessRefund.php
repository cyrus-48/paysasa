<?php

declare(strict_types=1);

namespace Paysasa\Payments\Actions;

use Paysasa\Payments\Contracts\Refundable;
use Paysasa\Payments\DTOs\RefundRequest;
use Paysasa\Payments\DTOs\RefundResponse;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Enums\RefundStatus;
use Paysasa\Payments\Events\PaymentRefunded;
use Paysasa\Payments\Exceptions\UnsupportedOperationException;
use Paysasa\Payments\Models\Payment;
use Paysasa\Payments\Models\Refund;
use Paysasa\Payments\Support\AuditLogger;

class ProcessRefund
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    public function execute(Refundable $driver, Payment $payment, RefundRequest $request): RefundResponse
    {
        if (! $driver instanceof \Paysasa\Payments\Contracts\PaymentDriver) {
            throw UnsupportedOperationException::make('unknown', 'refund');
        }

        $refund = Refund::create([
            'payment_id' => $payment->id,
            'currency' => $payment->currency,
            'amount' => $request->amount ?? $payment->amount,
            'status' => RefundStatus::Pending,
            'reason' => $request->reason,
        ]);

        $response = $driver->refund($request);

        $refund->update([
            'status' => $response->status,
            'provider_reference' => $response->providerReference,
            'raw_response' => is_array($response->rawResponse) ? $response->rawResponse : null,
        ]);

        if ($response->successful()) {
            $isFullRefund = $request->amount === null || $request->amount >= $payment->amount;
            $payment->update(['status' => $isFullRefund ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded]);

            $this->audit->record('refund.issued', $payment, [], [
                'refund_id' => $refund->uuid,
                'amount' => $response->amount,
            ]);

            event(new PaymentRefunded($payment, $response));
        }

        return $response;
    }
}
