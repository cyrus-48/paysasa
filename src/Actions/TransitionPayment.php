<?php

declare(strict_types=1);

namespace Paysasa\Payments\Actions;

use Illuminate\Support\Facades\DB;
use Paysasa\Payments\Contracts\PaymentRepository;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\DTOs\TransitionResult;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Events\PaymentAuthorized;
use Paysasa\Payments\Events\PaymentCancelled;
use Paysasa\Payments\Events\PaymentExpired;
use Paysasa\Payments\Events\PaymentFailed;
use Paysasa\Payments\Events\PaymentProcessing;
use Paysasa\Payments\Events\PaymentRefunded;
use Paysasa\Payments\Events\PaymentReversed;
use Paysasa\Payments\Events\PaymentStateConflict;
use Paysasa\Payments\Events\PaymentSuccessful;
use Paysasa\Payments\Models\Payment;

final class TransitionPayment
{
    public function __construct(private readonly PaymentRepository $repo)
    {
    }

    public function __invoke(Payment $payment, PaymentResponse $response, string $source): TransitionResult
    {
        return DB::transaction(function () use ($payment, $response, $source) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $from = $locked->status;
            $to = $response->status;

            if ($from === $to) {
                $locked = $this->repo->updateFromResponse($locked, $response);

                return TransitionResult::unchanged($locked);
            }

            if (! $from->canTransitionTo($to)) {
                event(new PaymentStateConflict($locked, $response, $source));

                return TransitionResult::conflict($locked);
            }

            if ($from === PaymentStatus::Expired && $to === PaymentStatus::Successful) {
                $flags = $locked->flags ?? [];
                $flags['late_confirmation'] = true;
                $locked->flags = $flags;
            }

            $locked = $this->repo->updateFromResponse($locked, $response);

            DB::afterCommit(fn () => $this->fireTerminalEvent($locked, $response, $from));

            return TransitionResult::changed($locked);
        });
    }

    private function fireTerminalEvent(Payment $payment, PaymentResponse $response, PaymentStatus $from): void
    {
        match ($payment->status) {
            PaymentStatus::Successful => event(new PaymentSuccessful($payment, $response)),
            PaymentStatus::Failed => event(new PaymentFailed($payment, $response)),
            PaymentStatus::Cancelled => event(new PaymentCancelled($payment, $response)),
            PaymentStatus::Expired => event(new PaymentExpired($payment, $response)),
            PaymentStatus::Authorized => event(new PaymentAuthorized($payment, $response)),
            PaymentStatus::Processing => event(new PaymentProcessing($payment, $response)),
            PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded => event(new PaymentRefunded($payment)),
            PaymentStatus::Reversed => event(new PaymentReversed($payment)),
            default => null,
        };
    }
}
