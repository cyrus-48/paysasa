<?php

declare(strict_types=1);

namespace Paysasa\Payments\Services\Airtel;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Paysasa\Payments\Contracts\TokenProvider;
use Paysasa\Payments\Exceptions\ProviderApiException;

/** OAuth2 client-credentials token acquisition for the Airtel Money OpenAPI (POST /auth/oauth2/token). */
class AirtelAuthenticator implements TokenProvider
{
    public function __construct(
        protected string $baseUrl,
        protected string $clientId,
        protected string $clientSecret,
    ) {
    }

    protected function cacheKey(): string
    {
        return 'paysasa:airtel:token:'.md5($this->baseUrl.$this->clientId);
    }

    public function token(): string
    {
        return Cache::remember($this->cacheKey(), now()->addMinutes(50), fn () => $this->fetchToken());
    }

    public function forceRefresh(): string
    {
        Cache::forget($this->cacheKey());

        return $this->token();
    }

    protected function fetchToken(): string
    {
        $response = Http::baseUrl($this->baseUrl)->timeout(15)->post('/auth/oauth2/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'client_credentials',
        ]);

        if ($response->failed()) {
            throw new ProviderApiException(
                'Failed to obtain Airtel Money OAuth token: HTTP '.$response->status(),
                $response->status(),
                $response->json(),
            );
        }

        return $response->json('access_token');
    }
}
