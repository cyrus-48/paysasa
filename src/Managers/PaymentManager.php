<?php

declare(strict_types=1);

namespace Paysasa\Payments\Managers;

use Illuminate\Support\Manager;
use Paysasa\Payments\Actions\InitiatePayment;
use Paysasa\Payments\Actions\ProcessRefund;
use Paysasa\Payments\Contracts\PaymentBuilder;
use Paysasa\Payments\Contracts\PaymentDriver as PaymentDriverContract;
use Paysasa\Payments\Exceptions\DriverNotFoundException;
use Paysasa\Payments\Support\CredentialVault;
use Paysasa\Payments\Support\FluentPaymentBuilder;
use Paysasa\Payments\Support\PaymentLogger;

/**
 * Laravel Manager Pattern implementation — the same shape as Cache, Mail,
 * Queue and Storage's managers. Resolves driver instances from
 * config('paysasa.drivers.*.driver'), caches them per (driver, merchant)
 * pair, and wraps every resolution in a fresh FluentPaymentBuilder so
 * chained state (->amount()->currency()->...) never leaks between calls.
 *
 * Third-party drivers register via extend(), exactly like a custom cache
 * store:
 *
 *   Payment::extend('equitel', fn ($app, $config) => new EquitelDriver($config, $app->make(PaymentLogger::class)));
 */
class PaymentManager extends Manager
{
    protected ?string $merchantId = null;

    public function getDefaultDriver(): string
    {
        return $this->container->make('config')->get('paysasa.default');
    }

    /** Scope subsequent driver() resolutions to a specific merchant's ProviderAccount credentials. */
    public function forMerchant(string $merchantId): static
    {
        $clone = clone $this;
        $clone->merchantId = $merchantId;

        return $clone;
    }

    public function extend($driver, \Closure $callback): static
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    protected function createDriver($driver)
    {
        if (isset($this->customCreators[$driver])) {
            $vault = $this->container->make(CredentialVault::class);

            return $this->customCreators[$driver]($this->container, $vault->resolve($driver, $this->merchantId));
        }

        $config = $this->container->make('config');
        $definition = $config->get("paysasa.drivers.{$driver}");

        if ($definition === null || ! isset($definition['driver'])) {
            throw DriverNotFoundException::make((string) $driver);
        }

        $vault = $this->container->make(CredentialVault::class);
        $resolvedConfig = $vault->resolve($driver, $this->merchantId);

        return $this->container->make($definition['driver'], [
            'config' => $resolvedConfig,
            'logger' => $this->container->make(PaymentLogger::class),
        ]);
    }

    /** Resolve the raw driver instance without the fluent builder wrapper — used by the webhook dispatcher and status-verification jobs. */
    public function driverInstance(?string $driver = null): PaymentDriverContract
    {
        $name = $driver ?: $this->getDefaultDriver();
        $cacheKey = $name.'|'.($this->merchantId ?? '');

        if (! isset($this->drivers[$cacheKey])) {
            $this->drivers[$cacheKey] = $this->createDriver($name);
        }

        return $this->drivers[$cacheKey];
    }

    public function driver($driver = null): PaymentBuilder
    {
        $instance = $this->driverInstance($driver);

        return new FluentPaymentBuilder(
            $instance,
            $this->container->make(InitiatePayment::class),
            $this->container->make(ProcessRefund::class),
            $this->container->make('config')->get('paysasa.currency', 'KES'),
        );
    }
}
