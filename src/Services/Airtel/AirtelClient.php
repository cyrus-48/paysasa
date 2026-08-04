<?php

declare(strict_types=1);

namespace Paysasa\Payments\Services\Airtel;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Paysasa\Payments\Exceptions\ProviderApiException;

/** Thin client for the Airtel Money OpenAPI (Collections + Disbursements). */
class AirtelClient
{
    public function __construct(
        protected string $baseUrl,
        protected AirtelAuthenticator $auth,
        protected string $country,
        protected string $currency,
    ) {
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->auth->token())
            ->acceptJson()
            ->withHeaders([
                'X-Country' => $this->country,
                'X-Currency' => $this->currency,
            ])
            ->timeout(30);
    }

    /** USSD push collection request. */
    public function collect(array $params): array
    {
        $response = $this->http()->post('/merchant/v1/payments/', [
            'reference' => $params['reference'],
            'subscriber' => ['country' => $this->country, 'currency' => $this->currency, 'msisdn' => $params['phone']],
            'transaction' => [
                'amount' => $params['amount'],
                'country' => $this->country,
                'currency' => $this->currency,
                'id' => $params['transaction_id'] ?? (string) Str::uuid(),
            ],
        ]);

        return $this->decode($response, 'collection request');
    }

    public function transactionEnquiry(string $transactionId): array
    {
        $response = $this->http()->get("/standard/v1/payments/{$transactionId}");

        return $this->decode($response, 'transaction enquiry');
    }

    /** Disbursement (payout / B2C). */
    public function disburse(array $params): array
    {
        $response = $this->http()->post('/standard/v1/disbursements/', [
            'payee' => ['msisdn' => $params['phone']],
            'reference' => $params['reference'],
            'pin' => $params['encrypted_pin'] ?? null,
            'transaction' => [
                'amount' => $params['amount'],
                'id' => $params['transaction_id'] ?? (string) Str::uuid(),
            ],
        ]);

        return $this->decode($response, 'disbursement');
    }

    public function refund(string $transactionId): array
    {
        $response = $this->http()->post('/standard/v1/payments/refund', [
            'transaction' => ['airtel_money_id' => $transactionId],
        ]);

        return $this->decode($response, 'refund');
    }

    protected function decode(Response $response, string $context): array
    {
        if ($response->failed()) {
            throw new ProviderApiException(
                "Airtel Money API error during {$context}: HTTP {$response->status()}",
                $response->status(),
                $response->json() ?? $response->body(),
            );
        }

        return $response->json() ?? [];
    }
}
