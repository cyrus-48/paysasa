<?php

declare(strict_types=1);

namespace Paysasa\Payments\Drivers\Banking;

use Illuminate\Http\Client\Response;
use Paysasa\Payments\Contracts\BankingGateway;
use Paysasa\Payments\Contracts\PayoutCapable;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Drivers\AbstractDriver;
use Paysasa\Payments\Enums\PaymentStatus;
use Ramsey\Uuid\Uuid;

/**
 * Shared plumbing for bank-rail drivers (PesaLink, EFT, RTGS, direct
 * host-to-host bank APIs). No two Kenyan banks expose an identical wire
 * format for these rails — unlike Daraja or Stripe there is no single
 * public standard — so this class implements the generic REST shape most
 * bank/aggregator (Cellulant, Craft Silicon, IPSL) integrations follow
 * (API-key or mutual-TLS auth, JSON name-inquiry + fund-transfer +
 * status-query endpoints) and leaves `endpointPrefix()` plus payload field
 * names as the seam to override per bank. Ship one concrete subclass per
 * bank you actually integrate with, following PesaLinkDriver as the
 * template, rather than trying to make one class fit every bank.
 */
abstract class AbstractBankingDriver extends AbstractDriver implements BankingGateway, PayoutCapable
{
    /** REST path prefix for this rail, e.g. '/pesalink/v1'. */
    abstract protected function endpointPrefix(): string;

    public function resolveAccount(string $accountNumber, string $bankCode): array
    {
        $response = $this->http()->withToken($this->config['api_key'] ?? '')->post($this->endpointPrefix().'/name-inquiry', [
            'accountNumber' => $accountNumber,
            'bankCode' => $bankCode,
        ]);

        $this->throwIfFailed($response, 'account name inquiry');

        $result = $response->json() ?? [];

        return [
            'account_number' => $accountNumber,
            'account_name' => $result['accountName'] ?? $result['account_name'] ?? null,
            'bank_code' => $bankCode,
            'raw' => $result,
        ];
    }

    public function transfer(ChargeRequest $request): PaymentResponse
    {
        $reference = $request->reference ?? Uuid::uuid4()->toString();

        $response = $this->http()->withToken($this->config['api_key'] ?? '')->post($this->endpointPrefix().'/fund-transfer', [
            'reference' => $reference,
            'amount' => $request->amount,
            'currency' => $request->currency->value,
            'beneficiaryAccountNumber' => $request->metadata['account_number'] ?? $request->phone,
            'beneficiaryBankCode' => $request->metadata['bank_code'] ?? $this->config['bank_code'] ?? null,
            'narration' => $request->description ?? 'Transfer',
        ]);

        $this->throwIfFailed($response, 'fund transfer');

        return $this->responseFromTransfer($response, $reference);
    }

    public function charge(ChargeRequest $request): PaymentResponse
    {
        return $this->transfer($request);
    }

    public function payout(ChargeRequest $request): PaymentResponse
    {
        return $this->transfer($request);
    }

    public function verify(string $providerReference): PaymentResponse
    {
        $response = $this->http()->withToken($this->config['api_key'] ?? '')->get($this->endpointPrefix()."/transactions/{$providerReference}");
        $this->throwIfFailed($response, 'transaction status query');

        return $this->responseFromTransfer($response, $providerReference);
    }

    protected function responseFromTransfer(Response $response, string $reference): PaymentResponse
    {
        $result = $response->json() ?? [];
        $status = strtoupper((string) ($result['status'] ?? ''));

        $paymentStatus = match ($status) {
            'SUCCESS', 'COMPLETED', 'SUCCESSFUL' => PaymentStatus::Successful,
            'FAILED', 'REJECTED' => PaymentStatus::Failed,
            'PENDING', 'PROCESSING', 'QUEUED' => PaymentStatus::Processing,
            default => PaymentStatus::Pending,
        };

        return new PaymentResponse(
            $this->provider(),
            $paymentStatus,
            providerReference: $result['transactionId'] ?? $reference,
            amount: isset($result['amount']) ? (float) $result['amount'] : null,
            message: $result['message'] ?? null,
            receiptNumber: $result['receiptNumber'] ?? null,
            rawResponse: $result,
        );
    }
}
