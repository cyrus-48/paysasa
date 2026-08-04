<?php

declare(strict_types=1);

namespace Paysasa\Payments\Services\Pesapal;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Paysasa\Payments\Exceptions\ProviderApiException;

/** Client for the Pesapal v3 API (JSON, Bearer token from RequestToken). */
class PesapalClient
{
    public function __construct(
        protected string $baseUrl,
        protected string $consumerKey,
        protected string $consumerSecret,
    ) {
    }

    protected function token(): string
    {
        return Cache::remember(
            'paysasa:pesapal:token:'.md5($this->baseUrl.$this->consumerKey),
            now()->addMinutes(4), // Pesapal tokens expire in ~5 minutes
            fn () => $this->requestToken(),
        );
    }

    protected function requestToken(): string
    {
        $response = Http::baseUrl($this->baseUrl)->timeout(15)->post('/api/Auth/RequestToken', [
            'consumer_key' => $this->consumerKey,
            'consumer_secret' => $this->consumerSecret,
        ]);

        if ($response->failed()) {
            throw new ProviderApiException('Failed to obtain Pesapal token: HTTP '.$response->status(), $response->status(), $response->json());
        }

        return $response->json('token');
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)->withToken($this->token())->acceptJson()->timeout(30);
    }

    public function registerIpn(string $url, string $notificationType = 'GET'): array
    {
        return $this->decode($this->http()->post('/api/URLSetup/RegisterIPN', [
            'url' => $url,
            'ipn_notification_type' => $notificationType,
        ]), 'IPN registration');
    }

    public function submitOrderRequest(array $params): array
    {
        return $this->decode($this->http()->post('/api/Transactions/SubmitOrderRequest', $params), 'submit order request');
    }

    public function getTransactionStatus(string $orderTrackingId): array
    {
        return $this->decode(
            $this->http()->get('/api/Transactions/GetTransactionStatus', ['orderTrackingId' => $orderTrackingId]),
            'get transaction status',
        );
    }

    public function refundRequest(array $params): array
    {
        return $this->decode($this->http()->post('/api/Transactions/RefundRequest', $params), 'refund request');
    }

    protected function decode(Response $response, string $context): array
    {
        if ($response->failed()) {
            throw new ProviderApiException("Pesapal API error during {$context}: HTTP {$response->status()}", $response->status(), $response->json());
        }

        return $response->json() ?? [];
    }
}
