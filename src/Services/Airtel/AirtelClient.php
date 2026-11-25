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
    /** Default API paths; `collection` and `disbursement` can be overridden from config (drivers.airtel.paths). */
    public const DEFAULT_PATHS = [
        'collection' => '/merchant/v1/payments/',
        'enquiry' => '/standard/v1/payments/',
        'refund' => '/standard/v1/payments/refund',
        'disbursement' => '/standard/v2/disbursements/',
    ];

    /** @var array<string, string> */
    protected array $paths;

    /**
     * @param array<string, mixed> $paths overrides for DEFAULT_PATHS (blank values are ignored)
     */
    public function __construct(
        protected string $baseUrl,
        protected AirtelAuthenticator $auth,
        protected string $country,
        protected string $currency,
        array $paths = [],
    ) {
        $this->paths = array_merge(self::DEFAULT_PATHS, array_filter(
            array_map(fn ($path) => is_string($path) ? trim($path) : '', $paths),
            fn (string $path) => $path !== '',
        ));
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

    /**
     * Send a request, and if Airtel answers 401 (token expired or revoked
     * earlier than we expected) fetch a fresh token and retry exactly once.
     *
     * @param callable(PendingRequest): Response $request
     */
    protected function send(callable $request): Response
    {
        $response = $request($this->http());

        if ($response->status() === 401) {
            $this->auth->forceRefresh();
            $response = $request($this->http());
        }

        return $response;
    }

    /** USSD push collection request. */
    public function collect(array $params): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post($this->paths['collection'], [
            'reference' => $params['reference'],
            'subscriber' => ['country' => $this->country, 'currency' => $this->currency, 'msisdn' => $params['phone']],
            'transaction' => [
                'amount' => $params['amount'],
                'country' => $this->country,
                'currency' => $this->currency,
                'id' => $params['transaction_id'] ?? (string) Str::uuid(),
            ],
        ]));

        return $this->decode($response, 'collection request');
    }

    public function transactionEnquiry(string $transactionId): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get(rtrim($this->paths['enquiry'], '/').'/'.rawurlencode($transactionId)));

        return $this->decode($response, 'transaction enquiry');
    }

    /** Disbursement (payout / B2C). */
    public function disburse(array $params): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post($this->paths['disbursement'], [
            'payee' => ['msisdn' => $params['phone']],
            'reference' => $params['reference'],
            'pin' => $params['encrypted_pin'] ?? null,
            'transaction' => [
                'amount' => $params['amount'],
                'id' => $params['transaction_id'] ?? (string) Str::uuid(),
            ],
        ]));

        return $this->decode($response, 'disbursement');
    }

    /** Refund by Airtel's own transaction id (`airtel_money_id`, the receipt number), not the merchant's. */
    public function refund(string $transactionId): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post($this->paths['refund'], [
            'transaction' => ['airtel_money_id' => $transactionId],
        ]));

        return $this->decode($response, 'refund');
    }

    protected function decode(Response $response, string $context): array
    {
        if ($response->failed()) {
            $detail = $this->errorDetail($response);

            throw new ProviderApiException(
                "Airtel Money API error during {$context}: HTTP {$response->status()}".($detail !== null ? " ({$detail})" : ''),
                $response->status(),
                $response->json() ?? $response->body(),
            );
        }

        return $response->json() ?? [];
    }

    /** Airtel reports errors as `status.message`, `error_description` or `message`, depending on the endpoint. */
    protected function errorDetail(Response $response): ?string
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        foreach (['status.message', 'error_description', 'message', 'error'] as $key) {
            $value = data_get($body, $key);

            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 200);
            }
        }

        return null;
    }
}
