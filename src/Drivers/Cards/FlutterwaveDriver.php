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
use Paysasa\Payments\Services\Flutterwave\FlutterwaveClient;
use Ramsey\Uuid\Uuid;

/** Hosted-checkout flow: charge() returns Pending with a `link` in metadata to redirect the customer to. */
class FlutterwaveDriver extends AbstractDriver implements PayoutCapable, Refundable, WebhookHandler
{
    protected ?FlutterwaveClient $client = null;

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Flutterwave;
    }

    protected function baseUrl(): string
    {
        return $this->config['base_url'] ?? 'https://api.flutterwave.com/v3';
    }

    protected function client(): FlutterwaveClient
    {
        $this->requireConfig(['secret_key']);

        return $this->client ??= new FlutterwaveClient($this->baseUrl(), $this->config['secret_key']);
    }

    public function charge(ChargeRequest $request): PaymentResponse
    {
        $txRef = $request->reference ?? Uuid::uuid4()->toString();

        $result = $this->client()->initializePayment([
            'tx_ref' => $txRef,
            'amount' => $request->amount,
            'currency' => $request->currency->value,
            'redirect_url' => $request->callbackUrl,
            'customer' => [
                'email' => $request->customer?->email,
                'phonenumber' => $request->customer?->phone ?? $request->phone,
                'name' => $request->customer?->name,
            ],
            'customizations' => ['title' => $request->description ?? config('app.name')],
            'meta' => $request->metadata,
        ]);

        if (($result['status'] ?? null) !== 'success') {
            return PaymentResponse::makeFailed($this->provider(), message: $result['message'] ?? 'Payment initialization failed', rawResponse: $result);
        }

        return PaymentResponse::makePending(
            $this->provider(),
            transactionId: null,
            providerReference: $txRef,
            message: 'Redirect the customer to complete payment',
            rawResponse: $result,
            metadata: ['link' => $result['data']['link'] ?? null],
        );
    }

    public function verify(string $providerReference): PaymentResponse
    {
        $result = $this->client()->verifyByReference($providerReference);

        return $this->responseFromTransaction($result['data'] ?? [], $providerReference);
    }

    public function payout(ChargeRequest $request): PaymentResponse
    {
        $result = $this->client()->transfer([
            'account_bank' => $request->metadata['bank_code'] ?? 'MPS', // MPS = Flutterwave's M-Pesa mobile money transfer code
            'account_number' => $request->phone,
            'amount' => $request->amount,
            'currency' => $request->currency->value,
            'reference' => $request->reference ?? Uuid::uuid4()->toString(),
            'narration' => $request->description ?? 'Payout',
        ]);

        $status = $result['data']['status'] ?? null;

        return in_array($status, ['NEW', 'PENDING'], true)
            ? PaymentResponse::makePending($this->provider(), null, (string) ($result['data']['id'] ?? null), 'Transfer queued', $result)
            : PaymentResponse::makeFailed($this->provider(), message: $result['message'] ?? 'Transfer failed', rawResponse: $result);
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        $result = $this->client()->refund($request->providerReference, $request->amount);
        $status = $result['data']['status'] ?? $result['status'] ?? null;

        return new RefundResponse(
            $this->provider(),
            in_array($status, ['completed', 'success'], true) ? RefundStatus::Completed : RefundStatus::Processing,
            refundId: isset($result['data']['id']) ? (string) $result['data']['id'] : null,
            providerReference: $request->providerReference,
            amount: $request->amount,
            rawResponse: $result,
        );
    }

    public function handleCallback(WebhookPayload $payload): PaymentResponse
    {
        $data = $payload->get('data', []);
        $txRef = $data['tx_ref'] ?? null;

        // Re-verify server-side rather than trusting the webhook body's status field directly (Flutterwave's own recommendation).
        if ($txRef !== null) {
            $result = $this->client()->verifyByReference($txRef);

            return $this->responseFromTransaction($result['data'] ?? [], $txRef);
        }

        return $this->responseFromTransaction($data, $txRef ?? '');
    }

    protected function responseFromTransaction(array $transaction, string $providerReference): PaymentResponse
    {
        $status = $transaction['status'] ?? null;

        return match ($status) {
            'successful' => PaymentResponse::makeSuccessful(
                $this->provider(),
                transactionId: null,
                providerReference: $providerReference,
                amount: isset($transaction['amount']) ? (float) $transaction['amount'] : null,
                currency: $transaction['currency'] ?? null,
                receiptNumber: isset($transaction['flw_ref']) ? (string) $transaction['flw_ref'] : null,
                rawResponse: $transaction,
            ),
            'failed' => PaymentResponse::makeFailed($this->provider(), message: 'Transaction failed', rawResponse: $transaction),
            default => PaymentResponse::makePending($this->provider(), null, $providerReference, rawResponse: $transaction),
        };
    }
}
