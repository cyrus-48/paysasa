<?php

declare(strict_types=1);

use Paysasa\Payments\Contracts\PaymentDriver;
use Paysasa\Payments\Exceptions\InvalidConfigurationException;
use Paysasa\Payments\Managers\PaymentManager;

/**
 * Every driver must be resolvable through the container with only
 * (array $config, PaymentLogger $logger) — the constructor signature the
 * whole Manager/CredentialVault resolution pipeline depends on. Missing
 * credentials are expected to throw InvalidConfigurationException lazily
 * (when a method that needs them runs), not at construction time, so this
 * test would catch a driver with a broken constructor signature or a
 * typo'd class name in config('paysasa.drivers.*.driver').
 */
it('resolves every configured driver through the container without a fatal error', function () {
    $manager = app(PaymentManager::class);

    foreach (array_keys(config('paysasa.drivers')) as $name) {
        $driver = $manager->driverInstance($name);

        expect($driver)->toBeInstanceOf(PaymentDriver::class);
    }
});

it('lazily throws InvalidConfigurationException rather than failing at construction for an unconfigured provider', function () {
    $driver = app(PaymentManager::class)->driverInstance('flutterwave');

    expect(fn () => $driver->charge(new \Paysasa\Payments\DTOs\ChargeRequest(
        provider: \Paysasa\Payments\Enums\PaymentProvider::Flutterwave,
        amount: 100,
        currency: \Paysasa\Payments\Enums\Currency::KES,
    )))->toThrow(InvalidConfigurationException::class);
});
