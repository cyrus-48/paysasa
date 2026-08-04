<?php

declare(strict_types=1);

namespace Paysasa\Payments\Services\Flutterwave;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Paysasa\Payments\Exceptions\ProviderApiException;

class FlutterwaveClient
{
    public function __construct(
        protected string $baseUrl,
        protected string $secretKey,
    ) {
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)->withToken($this->secretKey)->acceptJson()->timeout(30);
    }

    public function initializePayment(array $params): array
    {
        return $this->decode($this->http()->post('/payments', $params), 'initialize payment');
    }

    public function verifyByReference(string $txRef): array
    {
        return $this->decode($this->http()->get('/transactions/verify_by_reference', ['tx_ref' => $txRef]), 'verify by reference');
    }

    public function verifyById(string $transactionId): array
    {
        return $this->decode($this->http()->get("/transactions/{$transactionId}/verify"), 'verify transaction');
    }

    public function refund(string $transactionId, ?float $amount = null): array
    {
        $params = $amount !== null ? ['amount' => $amount] : [];

        return $this->decode($this->http()->post("/transactions/{$transactionId}/refund", $params), 'refund');
    }

    public function transfer(array $params): array
    {
        return $this->decode($this->http()->post('/transfers', $params), 'transfer');
    }

    protected function decode(Response $response, string $context): array
    {
        if ($response->failed()) {
            throw new ProviderApiException(
                "Flutterwave API error during {$context}: ".($response->json('message') ?? "HTTP {$response->status()}"),
                $response->status(),
                $response->json(),
            );
        }

        return $response->json() ?? [];
    }
}
