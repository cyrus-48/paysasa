<?php

declare(strict_types=1);

namespace Paysasa\Payments\Drivers\Cards;

use Paysasa\Payments\Contracts\Refundable;
use Paysasa\Payments\Contracts\WebhookHandler;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\DTOs\RefundRequest;
use Paysasa\Payments\DTOs\RefundResponse;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Drivers\AbstractDriver;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\RefundStatus;
use Paysasa\Payments\Services\Pesapal\PesapalClient;
use Ramsey\Uuid\Uuid;

/**
 * Pesapal is a hosted-checkout gateway: charge() submits an order and
 * returns Pending with the hosted `redirect_url` in metadata — the caller
 * redirects the customer there to enter card/mobile-money details on
 * Pesapal's PCI-compliant page. Final status arrives via the IPN callback
 * (handleCallback) or verify() polling GetTransactionStatus.
 */
class PesapalDriver extends AbstractDriver implements Refundable, WebhookHandler
{
    protected ?PesapalClient $client = null;

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Pesapal;
    }

    protected function client(): PesapalClient
    {
        $this->requireConfig(['consumer_key', 'consumer_secret']);

        return $this->client ??= new PesapalClient($this->baseUrl(), $this->config['consumer_key'], $this->config['consumer_secret']);
    }

    public function charge(ChargeRequest $request): PaymentResponse
    {
        $this->requireConfig(['ipn_url']);

        $ipn = $this->client()->registerIpn($this->config['ipn_url']);

        $result = $this->client()->submitOrderRequest([
            'id' => $request->reference ?? Uuid::uuid4()->toString(),
            'currency' => $request->currency->value,
            'amount' => $request->amount,
            'description' => $request->description ?? 'Payment',
            'callback_url' => $request->callbackUrl,
            'notification_id' => $ipn['ipn_id'] ?? null,
            'billing_address' => [
                'email_address' => $request->customer?->email,
                'phone_number' => $request->customer?->phone ?? $request->phone,
                'first_name' => $request->customer?->name,
            ],
        ]);

        if (! isset($result['order_tracking_id'])) {
            return PaymentResponse::makeFailed($this->provider(), message: $result['error']['message'] ?? 'Order submission failed', rawResponse: $result);
        }

        return PaymentResponse::makePending(
            $this->provider(),
            transactionId: null,
            providerReference: $result['order_tracking_id'],
            message: 'Redirect the customer to complete payment',
            rawResponse: $result,
            metadata: ['redirect_url' => $result['redirect_url'] ?? null],
        );
    }

    public function verify(string $providerReference): PaymentResponse
    {
        $result = $this->client()->getTransactionStatus($providerReference);

        return $this->responseFromStatus($result, $providerReference);
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        $result = $this->client()->refundRequest([
            'confirmation_code' => $request->providerReference,
            'amount' => $request->amount,
            'username' => config('app.name'),
            'remarks' => $request->reason ?? 'Refund',
        ]);

        $status = $result['status'] ?? null;

        return new RefundResponse(
            $this->provider(),
            $status === '200' ? RefundStatus::Processing : RefundStatus::Failed,
            providerReference: $request->providerReference,
            amount: $request->amount,
            message: $result['message'] ?? null,
            rawResponse: $result,
        );
    }

    public function handleCallback(WebhookPayload $payload): PaymentResponse
    {
        $orderTrackingId = $payload->get('OrderTrackingId') ?? $payload->get('orderTrackingId');
        $result = $this->client()->getTransactionStatus($orderTrackingId);

        return $this->responseFromStatus($result, $orderTrackingId);
    }

    protected function responseFromStatus(array $result, string $providerReference): PaymentResponse
    {
        $description = $result['payment_status_description'] ?? null;

        return match ($description) {
            'Completed' => PaymentResponse::makeSuccessful(
                $this->provider(),
                transactionId: null,
                providerReference: $providerReference,
                amount: isset($result['amount']) ? (float) $result['amount'] : null,
                currency: $result['currency'] ?? null,
                receiptNumber: $result['confirmation_code'] ?? null,
                rawResponse: $result,
            ),
            'Failed' => PaymentResponse::makeFailed($this->provider(), message: 'Payment failed', rawResponse: $result),
            'Invalid' => PaymentResponse::makeFailed($this->provider(), message: 'Invalid order', rawResponse: $result),
            'Reversed' => new PaymentResponse($this->provider(), \Paysasa\Payments\Enums\PaymentStatus::Reversed, providerReference: $providerReference, rawResponse: $result),
            default => PaymentResponse::makePending($this->provider(), null, $providerReference, rawResponse: $result),
        };
    }
}
