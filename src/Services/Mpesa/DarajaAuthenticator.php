<?php

declare(strict_types=1);

namespace Paysasa\Payments\Services\Mpesa;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Paysasa\Payments\Contracts\TokenProvider;
use Paysasa\Payments\Exceptions\ProviderApiException;

/**
 * OAuth2 client-credentials token acquisition for the Daraja API
 * (GET /oauth/v1/generate?grant_type=client_credentials, HTTP Basic auth
 * with consumer key/secret). Daraja tokens are valid for ~3599 seconds;
 * this caches with a safety margin so a near-expiry token is never handed
 * to a driver mid-request.
 */
class DarajaAuthenticator implements TokenProvider
{
    public function __construct(
        protected string $baseUrl,
        protected string $consumerKey,
        protected string $consumerSecret,
    ) {
    }

    protected function cacheKey(): string
    {
        return 'paysasa:mpesa:token:'.md5($this->baseUrl.$this->consumerKey);
    }

    public function token(): string
    {
        return Cache::remember($this->cacheKey(), now()->addSeconds(3500), fn () => $this->fetchToken());
    }

    public function forceRefresh(): string
    {
        Cache::forget($this->cacheKey());

        return $this->token();
    }

    protected function fetchToken(): string
    {
        $response = Http::baseUrl($this->baseUrl)
            ->withBasicAuth($this->consumerKey, $this->consumerSecret)
            ->timeout(15)
            ->get('/oauth/v1/generate', ['grant_type' => 'client_credentials']);

        if ($response->failed()) {
            throw new ProviderApiException(
                'Failed to obtain Daraja OAuth token: HTTP '.$response->status(),
                $response->status(),
                $response->json(),
            );
        }

        return $response->json('access_token');
    }
}
