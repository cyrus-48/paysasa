<?php

declare(strict_types=1);

namespace Paysasa\Payments\Actions;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Paysasa\Payments\Contracts\PaymentDriver;
use Paysasa\Payments\Contracts\PaymentRepository;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Events\PaymentInitiated;
use Paysasa\Payments\Events\PaymentProcessing;
use Paysasa\Payments\Exceptions\FraudSuspectedException;
use Paysasa\Payments\Exceptions\InvalidAmountException;
use Paysasa\Payments\Exceptions\InvalidConfigurationException;
use Paysasa\Payments\Exceptions\InvalidPhoneNumberException;
use Paysasa\Payments\Exceptions\ProviderApiException;
use Paysasa\Payments\Exceptions\UnsupportedOperationException;
use Paysasa\Payments\Support\IdempotencyManager;
use Paysasa\Payments\Support\PaymentLogger;

/**
 * The orchestration entry point for every charge: this is the ONLY place
 * that sequences fraud checks -> idempotency -> persistence -> driver call
 * -> persistence -> events, so that behaviour is identical no matter which
 * driver or which entry point (FluentPaymentBuilder, an Artisan command, a
 * queued job retry) triggered the charge.
 */
class InitiatePayment
{
    public function __construct(
        protected PaymentRepository $repository,
        protected IdempotencyManager $idempotency,
        protected RunFraudChecks $fraudChecks,
        protected PaymentLogger $logger,
        protected TransitionPayment $transition,
    ) {
    }

    public function execute(PaymentDriver $driver, ChargeRequest $request): PaymentResponse
    {
        return $this->idempotency->remember($request, function () use ($driver, $request) {
            $payment = $this->repository->createFromRequest($request);

            event(new PaymentInitiated($payment, $request));

            try {
                $this->fraudChecks->execute($request);
            } catch (FraudSuspectedException $e) {
                $response = PaymentResponse::makeFailed($request->provider, (string) $payment->uuid, $e->getMessage());
                $this->repository->recordAttempt($payment, $response, $e);
                ($this->transition)($payment, $response, 'sync');

                return $response;
            }

            event(new PaymentProcessing($payment));

            try {
                $response = $driver->charge($request);
            } catch (ConnectionException|ConnectException $e) {
                $this->logger->error('Driver charge() connection timed out', [
                    'exception' => $e->getMessage(),
                ], $payment, $driver->provider()->value);

                $response = PaymentResponse::makePending(
                    $request->provider,
                    (string) $payment->uuid,
                    null,
                    'Payment outcome uncertain due to network timeout. Verification or webhook pending.'
                );

                $this->repository->recordAttempt($payment, $response, $e);
                // expires_at was already set when the payment was created.
                $payment->update(['outcome_uncertain' => true]);

                return $response;
            } catch (ProviderApiException $e) {
                $this->logger->error('Driver charge() raised a provider API exception', [
                    'exception' => $e->getMessage(),
                    'status_code' => $e->statusCode(),
                ], $payment, $driver->provider()->value);

                $response = PaymentResponse::makeFailed($request->provider, (string) $payment->uuid, $e->getMessage(), $e->rawResponse());
                $this->repository->recordAttempt($payment, $response, $e);
                ($this->transition)($payment, $response, 'sync');

                return $response;
            } catch (InvalidPhoneNumberException|InvalidAmountException|InvalidConfigurationException|UnsupportedOperationException $e) {
                // Rejected by the driver before anything was sent to the provider.
                // Close the payment out as Failed instead of leaving an orphaned
                // Pending row (which an idempotent retry would otherwise "replay"
                // as a pending payment that never existed at the provider), then
                // let the caller see the real error. A failed attempt consumes
                // its idempotency key: retry with a corrected request and a new key.
                $response = PaymentResponse::makeFailed($request->provider, (string) $payment->uuid, $e->getMessage());
                $this->repository->recordAttempt($payment, $response, $e);
                ($this->transition)($payment, $response, 'sync');

                throw $e;
            }

            $this->repository->recordAttempt($payment, $response);
            ($this->transition)($payment, $response, 'sync');

            if ($response->pending()) {
                $delaySeconds = (int) config("paysasa.drivers.{$driver->provider()->value}.initial_verify_delay", 45);
                \Illuminate\Support\Facades\DB::afterCommit(function () use ($payment, $driver, $delaySeconds) {
                    \Paysasa\Payments\Jobs\VerifyPaymentStatusJob::dispatch($payment, $driver->provider()->value)
                        ->delay(now()->addSeconds($delaySeconds));
                });
            }

            return $response;
        });
    }
}
