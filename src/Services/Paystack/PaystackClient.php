<?php

declare(strict_types=1);

namespace Paysasa\Payments\Services\Paystack;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Paysasa\Payments\Exceptions\ProviderApiException;

class PaystackClient
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

    public function initializeTransaction(array $params): array
    {
        return $this->decode($this->http()->post('/transaction/initialize', $params), 'initialize transaction');
    }

    public function verifyTransaction(string $reference): array
    {
        return $this->decode($this->http()->get('/transaction/verify/'.rawurlencode($reference)), 'verify transaction');
    }

    public function createRefund(array $params): array
    {
        return $this->decode($this->http()->post('/refund', $params), 'create refund');
    }

    public function createTransferRecipient(array $params): array
    {
        return $this->decode($this->http()->post('/transferrecipient', $params), 'create transfer recipient');
    }

    public function initiateTransfer(array $params): array
    {
        return $this->decode($this->http()->post('/transfer', $params), 'initiate transfer');
    }

    protected function decode(Response $response, string $context): array
    {
        if ($response->failed()) {
            throw new ProviderApiException(
                "Paystack API error during {$context}: ".($response->json('message') ?? "HTTP {$response->status()}"),
                $response->status(),
                $response->json(),
            );
        }

        return $response->json() ?? [];
    }
}
