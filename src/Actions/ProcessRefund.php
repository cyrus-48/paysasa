<?php

declare(strict_types=1);

namespace Paysasa\Payments\Actions;

use Illuminate\Support\Facades\DB;
use Paysasa\Payments\Contracts\PaymentDriver;
use Paysasa\Payments\Contracts\Refundable;
use Paysasa\Payments\DTOs\RefundRequest;
use Paysasa\Payments\DTOs\RefundResponse;
use Paysasa\Payments\Enums\Currency;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Enums\RefundStatus;
use Paysasa\Payments\Events\PaymentRefunded;
use Paysasa\Payments\Exceptions\InvalidAmountException;
use Paysasa\Payments\Exceptions\InvalidConfigurationException;
use Paysasa\Payments\Exceptions\PaymentNotRefundableException;
use Paysasa\Payments\Exceptions\ProviderApiException;
use Paysasa\Payments\Exceptions\UnsupportedOperationException;
use Paysasa\Payments\Models\Payment;
use Paysasa\Payments\Models\Refund;
use Paysasa\Payments\Support\AuditLogger;

/**
 * Refund orchestration.
 *
 * The refundable balance is enforced under a row lock on the payment, and the
 * amount is *reserved* by persisting a pending Refund row before the provider
 * is called. Two concurrent requests therefore cannot both pass the check and
 * refund more than was paid, and a refund whose outcome is unknown (timeout)
 * keeps its reservation rather than silently freeing the amount for a second
 * attempt.
 */
class ProcessRefund
{
    /** Refund states that still count against the refundable balance. */
    private const RESERVING = [RefundStatus::Pending, RefundStatus::Processing, RefundStatus::Completed];

    public function __construct(protected AuditLogger $audit)
    {
    }

    public function execute(Refundable $driver, Payment $payment, RefundRequest $request): RefundResponse
    {
        if (! $driver instanceof PaymentDriver) {
            throw UnsupportedOperationException::make('unknown', 'refund');
        }

        $currency = Currency::tryFrom((string) $payment->currency) ?? Currency::KES;

        $requestedMinor = null;

        if ($request->amount !== null) {
            $requestedMinor = $currency->toMinorUnits($request->amount);

            if ($requestedMinor <= 0) {
                throw InvalidAmountException::nonPositive($request->amount);
            }
        }

        [$refund, $alreadyReservedMinor] = $this->reserve($payment, $requestedMinor, $request->reason);

        // When earlier refunds exist, "full refund" (a null amount) must mean
        // "the remaining balance", not the whole original charge. The payment's
        // currency is always passed along so drivers use the right exponent.
        $request = new RefundRequest(
            transactionId: $request->transactionId,
            providerReference: $request->providerReference,
            amount: $request->amount === null && $alreadyReservedMinor > 0
                ? $currency->fromMinorUnits((int) $refund->getAttribute('amount_minor'))
                : $request->amount,
            reason: $request->reason,
            // Providers that refund by their own id or only in full (Airtel) need these.
            metadata: array_merge($request->metadata, [
                'receipt_number' => $payment->receipt_number,
                'payment_amount' => $payment->amount,
            ]),
            currency: $request->currency ?? $currency->value,
        );

        try {
            $response = $driver->refund($request);
        } catch (ProviderApiException $e) {
            // A definite rejection (4xx) releases the reservation. A 5xx/429 is
            // ambiguous — the provider may still have processed it — so, like a
            // timeout below, it stays Pending and reserved.
            if (! $e->isRetryable()) {
                $refund->update([
                    'status' => RefundStatus::Failed,
                    'raw_response' => is_array($e->rawResponse()) ? $e->rawResponse() : null,
                ]);
            }

            throw $e;
        } catch (UnsupportedOperationException|InvalidAmountException|InvalidConfigurationException $e) {
            // The driver refused before anything reached the provider (e.g. a
            // partial refund on a provider that only refunds in full): nothing
            // can have been refunded, so release the reservation.
            $refund->update(['status' => RefundStatus::Failed]);

            throw $e;
        }
        // Any other throwable (e.g. a connection timeout) leaves the refund
        // Pending and reserved: the provider may well have processed it.

        $refund->update([
            'status' => $response->status,
            'provider_reference' => $response->providerReference,
            'raw_response' => is_array($response->rawResponse) ? $response->rawResponse : null,
        ]);

        if ($response->successful()) {
            $this->settlePaymentStatus($payment);

            $this->audit->record('refund.issued', $payment, [], [
                'refund_id' => $refund->uuid,
                'amount' => $response->amount,
            ]);

            event(new PaymentRefunded($payment, $response));
        }

        return $response;
    }

    /**
     * @return array{0: Refund, 1: int} the reserved refund and the minor units
     *                                  already reserved by earlier refunds
     */
    private function reserve(Payment $payment, ?int $requestedMinor, ?string $reason): array
    {
        return DB::transaction(function () use ($payment, $requestedMinor, $reason) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (! in_array($locked->status, [PaymentStatus::Successful, PaymentStatus::PartiallyRefunded], true)) {
                throw PaymentNotRefundableException::forStatus((string) $locked->uuid, $locked->status);
            }

            $reservedMinor = (int) Refund::query()
                ->where('payment_id', $locked->id)
                ->whereIn('status', array_map(fn (RefundStatus $s) => $s->value, self::RESERVING))
                ->sum('amount_minor');

            $refundableMinor = (int) $locked->amount_minor - $reservedMinor;
            $amountMinor = $requestedMinor ?? $refundableMinor;

            if ($refundableMinor <= 0 || $amountMinor > $refundableMinor) {
                throw InvalidAmountException::exceedsRefundable($amountMinor, max(0, $refundableMinor));
            }

            $refund = Refund::create([
                'payment_id' => $locked->id,
                'currency' => $locked->currency,
                'amount_minor' => $amountMinor,
                'status' => RefundStatus::Pending,
                'reason' => $reason,
            ]);

            return [$refund, $reservedMinor];
        });
    }

    /**
     * Derive Refunded vs PartiallyRefunded from what has actually completed,
     * under a row lock, and only along a legal transition.
     */
    private function settlePaymentStatus(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $completedMinor = (int) Refund::query()
                ->where('payment_id', $locked->id)
                ->where('status', RefundStatus::Completed->value)
                ->sum('amount_minor');

            $target = $completedMinor >= (int) $locked->amount_minor
                ? PaymentStatus::Refunded
                : PaymentStatus::PartiallyRefunded;

            if ($locked->status === $target || $locked->status->canTransitionTo($target)) {
                $locked->status = $target;
                $locked->save();
            }
        });

        $payment->refresh();
    }
}
