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
    /** Used only when Airtel's response carries no usable `expires_in`. */
    private const FALLBACK_TTL_SECONDS = 120;

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
        $cached = Cache::get($this->cacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->fetchAndCache();
    }

    public function forceRefresh(): string
    {
        Cache::forget($this->cacheKey());

        return $this->fetchAndCache();
    }

    /**
     * Cache for the lifetime Airtel reports (minus a safety margin) rather than
     * a fixed guess: token lifetimes differ between environments and a token
     * cached past its expiry turns every call into a 401.
     */
    protected function fetchAndCache(): string
    {
        [$token, $expiresIn] = $this->fetchToken();

        $ttl = $expiresIn > 0 ? max(30, $expiresIn - 60) : self::FALLBACK_TTL_SECONDS;

        Cache::put($this->cacheKey(), $token, now()->addSeconds($ttl));

        return $token;
    }

    /** @return array{0: string, 1: int} the access token and its reported lifetime in seconds */
    protected function fetchToken(): array
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

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new ProviderApiException(
                'Airtel Money OAuth response did not contain an access_token.',
                $response->status(),
                $response->json(),
            );
        }

        return [$token, (int) $response->json('expires_in')];
    }
}
