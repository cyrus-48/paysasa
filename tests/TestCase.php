<?php

declare(strict_types=1);

namespace Paysasa\Payments\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Paysasa\Payments\PaysasaServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PaysasaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('paysasa.drivers.mpesa.consumer_key', 'test-key');
        $app['config']->set('paysasa.drivers.mpesa.consumer_secret', 'test-secret');
        $app['config']->set('paysasa.drivers.mpesa.shortcode', '174379');
        $app['config']->set('paysasa.drivers.mpesa.passkey', 'test-passkey');
        $app['config']->set('paysasa.drivers.mpesa.stk_callback_url', 'https://example.test/paysasa/webhooks/mpesa');

        $app['config']->set('paysasa.drivers.stripe.secret_key', 'sk_test_fake');
        $app['config']->set('paysasa.drivers.stripe.webhook_secret', 'whsec_test_fake');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
