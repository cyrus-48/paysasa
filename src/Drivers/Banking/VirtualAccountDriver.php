<?php

declare(strict_types=1);

namespace Paysasa\Payments\Drivers\Banking;

use Paysasa\Payments\Contracts\VirtualAccountProvider;
use Paysasa\Payments\Contracts\WebhookHandler;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Drivers\AbstractDriver;
use Paysasa\Payments\Enums\PaymentProvider;

/**
 * Dedicated/virtual bank account numbers for collections: unlike a push
 * (STK, card charge), the flow is inverted — you provision an account
 * number up front and the customer (or their employer, in a payroll
 * deduction scenario) transfers into it whenever they choose. charge()
 * therefore provisions the account and returns Pending immediately; the
 * actual money movement is confirmed later via handleCallback() when the
 * bank posts a deposit notification.
 */
class VirtualAccountDriver extends AbstractDriver implements VirtualAccountProvider, WebhookHandler
{
    public function provider(): PaymentProvider
    {
        return PaymentProvider::VirtualAccount;
    }

    public function createVirtualAccount(string $customerReference, array $attributes = []): array
    {
        $this->requireConfig(['api_key', 'bank_code']);

        $response = $this->http()->withToken($this->config['api_key'])->post('/virtual-accounts', array_merge([
            'reference' => $customerReference,
            'bankCode' => $this->config['bank_code'],
            'accountPrefix' => $this->config['account_prefix'] ?? 'PSS',
        ], $attributes));

        $this->throwIfFailed($response, 'virtual account creation');

        $result = $response->json() ?? [];

        return [
            'account_number' => $result['accountNumber'] ?? null,
            'account_name' => $result['accountName'] ?? null,
            'bank_code' => $this->config['bank_code'],
            'raw' => $result,
        ];
    }

    public function deactivateVirtualAccount(string $accountNumber): void
    {
        $response = $this->http()->withToken($this->config['api_key'])->delete("/virtual-accounts/{$accountNumber}");

        $this->throwIfFailed($response, 'virtual account deactivation');
    }

    public function charge(ChargeRequest $request): PaymentResponse
    {
        $account = $this->createVirtualAccount($request->reference ?? $request->customer?->id ?? 'customer', [
            'expectedAmount' => $request->amount,
        ]);

        return PaymentResponse::makePending(
            $this->provider(),
            transactionId: null,
            providerReference: $account['account_number'],
            message: 'Awaiting deposit into virtual account',
            metadata: $account,
        );
    }

    public function verify(string $providerReference): PaymentResponse
    {
        $response = $this->http()->withToken($this->config['api_key'])->get("/virtual-accounts/{$providerReference}/transactions/latest");
        $this->throwIfFailed($response, 'virtual account transaction lookup');

        $result = $response->json() ?? [];

        return ($result['status'] ?? null) === 'SUCCESS'
            ? PaymentResponse::makeSuccessful($this->provider(), null, $providerReference, amount: $result['amount'] ?? null, rawResponse: $result)
            : PaymentResponse::makePending($this->provider(), null, $providerReference, rawResponse: $result);
    }

    public function handleCallback(WebhookPayload $payload): PaymentResponse
    {
        return PaymentResponse::makeSuccessful(
            $this->provider(),
            transactionId: null,
            providerReference: $payload->get('accountNumber'),
            amount: $payload->get('amount') !== null ? (float) $payload->get('amount') : null,
            receiptNumber: $payload->get('transactionReference'),
            rawResponse: $payload->parsedBody,
        );
    }
}
