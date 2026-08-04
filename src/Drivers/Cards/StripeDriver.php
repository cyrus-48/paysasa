<?php

declare(strict_types=1);

namespace Paysasa\Payments\Drivers\Cards;

use Paysasa\Payments\Contracts\Authorizable;
use Paysasa\Payments\Contracts\Refundable;
use Paysasa\Payments\Contracts\WebhookHandler;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\DTOs\RefundRequest;
use Paysasa\Payments\DTOs\RefundResponse;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Drivers\AbstractDriver;
use Paysasa\Payments\Enums\Currency;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\RefundStatus;
use Paysasa\Payments\Exceptions\InvalidConfigurationException;
use Paysasa\Payments\Services\Stripe\StripeClient;

class StripeDriver extends AbstractDriver implements Authorizable, Refundable, WebhookHandler
{
    protected ?StripeClient $client = null;

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Stripe;
    }

    protected function client(): StripeClient
    {
        $this->requireConfig(['secret_key']);

        return $this->client ??= new StripeClient($this->config['secret_key'], $this->config['api_version'] ?? '2024-06-20');
    }

    protected function minorAmount(ChargeRequest $request): int
    {
        return $request->currency->toMinorUnits($request->amount);
    }

    public function charge(ChargeRequest $request): PaymentResponse
    {
        return $this->createIntent($request, captureMethod: 'automatic');
    }

    public function authorize(ChargeRequest $request): PaymentResponse
    {
        return $this->createIntent($request, captureMethod: 'manual');
    }

    protected function createIntent(ChargeRequest $request, string $captureMethod): PaymentResponse
    {
        $paymentMethod = $request->cardToken ?? $request->paymentMethodId;

        if ($paymentMethod === null) {
            throw InvalidConfigurationException::missingKeys('stripe', ['cardToken or paymentMethodId (charge request)']);
        }

        $params = [
            'amount' => $this->minorAmount($request),
            'currency' => strtolower($request->currency->value),
            'payment_method' => $paymentMethod,
            'confirm' => 'true',
            'capture_method' => $captureMethod,
            'description' => $request->description,
            'automatic_payment_methods[enabled]' => 'true',
            'automatic_payment_methods[allow_redirects]' => 'never',
        ];

        if ($request->reference) {
            $params['metadata[reference]'] = $request->reference;
        }

        foreach ($request->metadata as $key => $value) {
            $params["metadata[{$key}]"] = (string) $value;
        }

        $result = $this->client()->createPaymentIntent($params);

        return $this->responseFromIntent($result);
    }

    public function capture(string $providerReference, ?float $amount = null): PaymentResponse
    {
        $amountMinor = $amount !== null ? Currency::KES->toMinorUnits($amount) : null;
        $result = $this->client()->capturePaymentIntent($providerReference, $amountMinor);

        return $this->responseFromIntent($result);
    }

    public function void(string $providerReference): PaymentResponse
    {
        $result = $this->client()->cancelPaymentIntent($providerReference);

        return $this->responseFromIntent($result);
    }

    public function verify(string $providerReference): PaymentResponse
    {
        $result = $this->client()->retrievePaymentIntent($providerReference);

        return $this->responseFromIntent($result);
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        $params = ['payment_intent' => $request->providerReference];

        if ($request->amount !== null) {
            $params['amount'] = Currency::KES->toMinorUnits($request->amount);
        }

        if ($request->reason) {
            $params['reason'] = $request->reason;
        }

        $result = $this->client()->createRefund($params);

        return new RefundResponse(
            $this->provider(),
            $result['status'] === 'succeeded' ? RefundStatus::Completed : RefundStatus::Processing,
            refundId: $result['id'] ?? null,
            providerReference: $result['payment_intent'] ?? null,
            amount: isset($result['amount']) ? $result['amount'] / 100 : null,
            rawResponse: $result,
        );
    }

    public function handleCallback(WebhookPayload $payload): PaymentResponse
    {
        $intent = $payload->get('data.object', []);

        return $this->responseFromIntent($intent, eventType: $payload->get('type'));
    }

    protected function responseFromIntent(array $intent, ?string $eventType = null): PaymentResponse
    {
        $status = $intent['status'] ?? null;
        $amount = isset($intent['amount']) ? $intent['amount'] / 100 : null;

        return match ($status) {
            'succeeded' => PaymentResponse::makeSuccessful(
                $this->provider(),
                transactionId: null,
                providerReference: $intent['id'] ?? null,
                amount: $amount,
                currency: isset($intent['currency']) ? strtoupper($intent['currency']) : null,
                message: $eventType,
                rawResponse: $intent,
            ),
            'canceled' => PaymentResponse::makeCancelled($this->provider(), message: 'PaymentIntent canceled', rawResponse: $intent),
            'requires_capture' => new PaymentResponse(
                $this->provider(),
                \Paysasa\Payments\Enums\PaymentStatus::Authorized,
                providerReference: $intent['id'] ?? null,
                amount: $amount,
                rawResponse: $intent,
            ),
            'requires_payment_method', 'requires_action' => PaymentResponse::makeFailed(
                $this->provider(),
                message: $intent['last_payment_error']['message'] ?? 'Payment requires further customer action',
                rawResponse: $intent,
            ),
            default => PaymentResponse::makePending($this->provider(), null, $intent['id'] ?? null, rawResponse: $intent),
        };
    }
}
