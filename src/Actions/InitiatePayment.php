<?php

declare(strict_types=1);

namespace Paysasa\Payments\Actions;

use Paysasa\Payments\Contracts\PaymentDriver;
use Paysasa\Payments\Contracts\PaymentRepository;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Events\PaymentFailed;
use Paysasa\Payments\Events\PaymentInitiated;
use Paysasa\Payments\Events\PaymentProcessing;
use Paysasa\Payments\Events\PaymentSuccessful;
use Paysasa\Payments\Exceptions\FraudSuspectedException;
use Paysasa\Payments\Exceptions\ProviderApiException;
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
                $this->repository->updateFromResponse($payment, $response);
                event(new PaymentFailed($payment, $response, $e));

                return $response;
            }

            event(new PaymentProcessing($payment));

            try {
                $response = $driver->charge($request);
            } catch (ProviderApiException $e) {
                $this->logger->error('Driver charge() raised a provider API exception', [
                    'exception' => $e->getMessage(),
                    'status_code' => $e->statusCode(),
                ], $payment, $driver->provider()->value);

                $response = PaymentResponse::makeFailed($request->provider, (string) $payment->uuid, $e->getMessage(), $e->rawResponse());
                $this->repository->recordAttempt($payment, $response, $e);
                $this->repository->updateFromResponse($payment, $response);
                event(new PaymentFailed($payment, $response, $e));

                return $response;
            }

            $this->repository->recordAttempt($payment, $response);
            $payment = $this->repository->updateFromResponse($payment, $response);

            match (true) {
                $response->status === PaymentStatus::Successful => event(new PaymentSuccessful($payment, $response)),
                $response->status === PaymentStatus::Failed => event(new PaymentFailed($payment, $response)),
                default => null, // Pending/Processing/Authorized: terminal event fires later from the webhook or VerifyPaymentStatusJob
            };

            return $response;
        });
    }
}
