<?php

declare(strict_types=1);

namespace Paysasa\Payments\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Paysasa\Payments\Contracts\PaymentDriver;
use Paysasa\Payments\Enums\Currency;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Exceptions\InvalidConfigurationException;
use Paysasa\Payments\Exceptions\ProviderApiException;
use Paysasa\Payments\Support\PaymentLogger;

/**
 * Template-method base class every concrete driver extends. Centralizes
 * the plumbing that would otherwise be duplicated 13 times: config access
 * with validation, sandbox/production base URL switching, an
 * HTTP client pre-configured with the package's timeout/retry policy, and
 * structured logging. Concrete drivers implement provider() plus whichever
 * capability interfaces (Refundable, PayoutCapable, ...) their gateway
 * actually supports — see Contracts/.
 */
abstract class AbstractDriver implements PaymentDriver
{
    public function __construct(
        protected array $config,
        protected PaymentLogger $logger,
    ) {
    }

    abstract public function provider(): PaymentProvider;

    protected function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /** @throws InvalidConfigurationException */
    protected function requireConfig(array $keys): void
    {
        $missing = [];

        foreach ($keys as $key) {
            if (blank($this->config[$key] ?? null)) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw InvalidConfigurationException::missingKeys($this->provider()->value, $missing);
        }
    }

    /**
     * Convert a provider's minor-unit integer to major units using the real
     * currency exponent (a hard-coded `/ 100` is wrong for zero-decimal
     * currencies such as RWF). Currencies outside the Currency enum are
     * treated as two-decimal, which holds for every other currency these
     * gateways settle in.
     */
    protected function toMajor(int|float $minor, ?string $currency): float
    {
        return $this->currencyFor($currency)->fromMinorUnits((int) round($minor));
    }

    /** Inverse of toMajor(): major units to the provider's minor-unit integer. */
    protected function toMinor(float $major, ?string $currency): int
    {
        return $this->currencyFor($currency)->toMinorUnits($major);
    }

    private function currencyFor(?string $code): Currency
    {
        return Currency::tryFrom(strtoupper((string) $code)) ?? Currency::KES;
    }

    protected function isSandbox(): bool
    {
        return ($this->config['env'] ?? config('paysasa.environment', 'sandbox')) !== 'production';
    }

    protected function baseUrl(): string
    {
        $urls = $this->config['base_urls'] ?? null;

        if (is_array($urls)) {
            return $urls[$this->isSandbox() ? 'sandbox' : 'production'] ?? '';
        }

        return $this->config['base_url'] ?? '';
    }

    /** Pre-configured HTTP client honouring config('paysasa.http.*'). Drivers should build requests off this rather than instantiating Guzzle/Http directly. */
    protected function http(): PendingRequest
    {
        $httpConfig = config('paysasa.http', []);

        return Http::baseUrl($this->baseUrl())
            ->timeout($httpConfig['timeout'] ?? 30)
            ->connectTimeout($httpConfig['connect_timeout'] ?? 10)
            ->retry(
                $httpConfig['retry']['times'] ?? 3,
                $httpConfig['retry']['sleep_milliseconds'] ?? 500,
                throw: false,
            )
            ->withOptions(['verify' => $httpConfig['verify_ssl'] ?? true]);
    }

    /** @throws ProviderApiException */
    protected function throwIfFailed(Response $response, string $context): void
    {
        if ($response->failed()) {
            throw new ProviderApiException(
                "{$this->provider()->label()} API error during {$context}: HTTP {$response->status()}",
                $response->status(),
                $response->json() ?? $response->body(),
                ['context' => $context],
            );
        }
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context, provider: $this->provider()->value);
    }
}
