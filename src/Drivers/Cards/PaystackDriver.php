<?php

declare(strict_types=1);

namespace Paysasa\Payments\Drivers\Cards;

use Paysasa\Payments\Contracts\PayoutCapable;
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
use Paysasa\Payments\Services\Paystack\PaystackClient;
use Ramsey\Uuid\Uuid;

class PaystackDriver extends AbstractDriver implements PayoutCapable, Refundable, WebhookHandler
{
    protected ?PaystackClient $client = null;

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Paystack;
    }

    protected function baseUrl(): string
    {
        return $this->config['base_url'] ?? 'https://api.paystack.co';
    }

    protected function client(): PaystackClient
    {
        $this->requireConfig(['secret_key']);

        return $this->client ??= new PaystackClient($this->baseUrl(), $this->config['secret_key']);
    }

    public function charge(ChargeRequest $request): PaymentResponse
    {
        $reference = $request->reference ?? Uuid::uuid4()->toString();

        $result = $this->client()->initializeTransaction([
            'email' => $request->customer?->email ?? 'customer@'.parse_url(config('app.url'), PHP_URL_HOST),
            'amount' => $request->currency->toMinorUnits($request->amount),
            'currency' => $request->currency->value,
            'reference' => $reference,
            'callback_url' => $request->callbackUrl,
            'metadata' => $request->metadata,
        ]);

        if (! ($result['status'] ?? false)) {
            return PaymentResponse::makeFailed($this->provider(), message: $result['message'] ?? 'Transaction initialization failed', rawResponse: $result);
        }

        return PaymentResponse::makePending(
            $this->provider(),
            transactionId: null,
            providerReference: $reference,
            message: 'Redirect the customer to complete payment',
            rawResponse: $result,
            metadata: ['authorization_url' => $result['data']['authorization_url'] ?? null],
        );
    }

    public function verify(string $providerReference): PaymentResponse
    {
        $result = $this->client()->verifyTransaction($providerReference);

        return $this->responseFromTransaction($result['data'] ?? [], $providerReference);
    }

    public function payout(ChargeRequest $request): PaymentResponse
    {
        $recipient = $this->client()->createTransferRecipient([
            'type' => 'mobile_money',
            'name' => $request->customer?->name ?? 'Recipient',
            'account_number' => $request->phone,
            'bank_code' => $request->metadata['bank_code'] ?? 'MPESA',
            'currency' => $request->currency->value,
        ]);

        $result = $this->client()->initiateTransfer([
            'source' => 'balance',
            'amount' => $request->currency->toMinorUnits($request->amount),
            'recipient' => $recipient['data']['recipient_code'] ?? null,
            'reason' => $request->description ?? 'Payout',
            'reference' => $request->reference ?? Uuid::uuid4()->toString(),
        ]);

        return ($result['status'] ?? false)
            ? PaymentResponse::makePending($this->provider(), null, $result['data']['reference'] ?? null, 'Transfer queued', $result)
            : PaymentResponse::makeFailed($this->provider(), message: $result['message'] ?? 'Transfer failed', rawResponse: $result);
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        $params = ['transaction' => $request->providerReference];

        if ($request->amount !== null) {
            $params['amount'] = (int) round($request->amount * 100);
        }

        $result = $this->client()->createRefund($params);
        $status = $result['data']['status'] ?? null;

        return new RefundResponse(
            $this->provider(),
            in_array($status, ['processed', 'success'], true) ? RefundStatus::Completed : RefundStatus::Processing,
            refundId: isset($result['data']['id']) ? (string) $result['data']['id'] : null,
            providerReference: $request->providerReference,
            amount: $request->amount,
            rawResponse: $result,
        );
    }

    public function handleCallback(WebhookPayload $payload): PaymentResponse
    {
        $data = $payload->get('data', []);
        $reference = $data['reference'] ?? null;

        // Re-verify server-side rather than trusting the webhook body directly.
        if ($reference !== null) {
            $result = $this->client()->verifyTransaction($reference);

            return $this->responseFromTransaction($result['data'] ?? [], $reference);
        }

        return $this->responseFromTransaction($data, $reference ?? '');
    }

    protected function responseFromTransaction(array $transaction, string $providerReference): PaymentResponse
    {
        $status = $transaction['status'] ?? null;

        return match ($status) {
            'success' => PaymentResponse::makeSuccessful(
                $this->provider(),
                transactionId: null,
                providerReference: $providerReference,
                amount: isset($transaction['amount']) ? $transaction['amount'] / 100 : null,
                currency: $transaction['currency'] ?? null,
                receiptNumber: $transaction['reference'] ?? null,
                rawResponse: $transaction,
            ),
            'failed' => PaymentResponse::makeFailed($this->provider(), message: $transaction['gateway_response'] ?? 'Transaction failed', rawResponse: $transaction),
            'abandoned' => PaymentResponse::makeCancelled($this->provider(), message: 'Checkout abandoned', rawResponse: $transaction),
            default => PaymentResponse::makePending($this->provider(), null, $providerReference, rawResponse: $transaction),
        };
    }
}
