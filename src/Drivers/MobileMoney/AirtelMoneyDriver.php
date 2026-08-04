<?php

declare(strict_types=1);

namespace Paysasa\Payments\Drivers\MobileMoney;

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
use Paysasa\Payments\Exceptions\InvalidConfigurationException;
use Paysasa\Payments\Services\Airtel\AirtelAuthenticator;
use Paysasa\Payments\Services\Airtel\AirtelClient;
use Ramsey\Uuid\Uuid;

class AirtelMoneyDriver extends AbstractDriver implements PayoutCapable, Refundable, WebhookHandler
{
    protected ?AirtelClient $client = null;

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Airtel;
    }

    protected function client(): AirtelClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $this->requireConfig(['client_id', 'client_secret']);

        $auth = new AirtelAuthenticator($this->baseUrl(), $this->config['client_id'], $this->config['client_secret']);

        return $this->client = new AirtelClient($this->baseUrl(), $auth, $this->config['country'] ?? 'KE', $this->config['currency'] ?? 'KES');
    }

    public function charge(ChargeRequest $request): PaymentResponse
    {
        if ($request->phone === null) {
            throw InvalidConfigurationException::missingKeys('airtel', ['phone (charge request)']);
        }

        $transactionId = Uuid::uuid4()->toString();

        $result = $this->client()->collect([
            'reference' => $request->reference ?? $transactionId,
            'phone' => $this->normalizePhone($request->phone),
            'amount' => $request->amount,
            'transaction_id' => $transactionId,
        ]);

        $status = $result['status']['success'] ?? false;

        if (! $status) {
            return PaymentResponse::makeFailed($this->provider(), message: $result['status']['message'] ?? 'Collection request rejected', rawResponse: $result);
        }

        return PaymentResponse::makePending(
            $this->provider(),
            transactionId: null,
            providerReference: $result['data']['transaction']['id'] ?? $transactionId,
            message: 'USSD push sent to customer',
            rawResponse: $result,
        );
    }

    public function verify(string $providerReference): PaymentResponse
    {
        $result = $this->client()->transactionEnquiry($providerReference);
        $txStatus = $result['data']['transaction']['status'] ?? null;

        return match ($txStatus) {
            'TS' => PaymentResponse::makeSuccessful($this->provider(), null, $providerReference, rawResponse: $result), // Transaction Successful
            'TF' => PaymentResponse::makeFailed($this->provider(), message: 'Transaction failed', rawResponse: $result),
            default => PaymentResponse::makePending($this->provider(), null, $providerReference, 'Awaiting confirmation', $result),
        };
    }

    public function payout(ChargeRequest $request): PaymentResponse
    {
        $transactionId = Uuid::uuid4()->toString();

        $result = $this->client()->disburse([
            'phone' => $this->normalizePhone($request->phone),
            'reference' => $request->reference ?? $transactionId,
            'amount' => $request->amount,
            'transaction_id' => $transactionId,
        ]);

        $status = $result['status']['success'] ?? false;

        return $status
            ? PaymentResponse::makePending($this->provider(), null, $result['data']['transaction']['id'] ?? $transactionId, 'Disbursement accepted', $result)
            : PaymentResponse::makeFailed($this->provider(), message: $result['status']['message'] ?? 'Disbursement rejected', rawResponse: $result);
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        $result = $this->client()->refund($request->providerReference ?? $request->transactionId);
        $success = $result['status']['success'] ?? false;

        return new RefundResponse(
            $this->provider(),
            $success ? RefundStatus::Completed : RefundStatus::Failed,
            refundId: $result['data']['transaction']['id'] ?? null,
            amount: $request->amount,
            message: $result['status']['message'] ?? null,
            rawResponse: $result,
        );
    }

    public function handleCallback(WebhookPayload $payload): PaymentResponse
    {
        $txStatus = $payload->get('transaction.status');
        $reference = $payload->get('transaction.id') ?? $payload->get('transaction.airtel_money_id');

        return match ($txStatus) {
            'TS', 'Success' => PaymentResponse::makeSuccessful(
                $this->provider(),
                transactionId: null,
                providerReference: $reference,
                amount: $payload->get('transaction.amount') !== null ? (float) $payload->get('transaction.amount') : null,
                rawResponse: $payload->parsedBody,
            ),
            default => PaymentResponse::makeFailed($this->provider(), message: 'Transaction failed', rawResponse: $payload->parsedBody),
        };
    }

    protected function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return str_starts_with($digits, '0') ? '254'.substr($digits, 1) : $digits;
    }
}
