<?php

declare(strict_types=1);

namespace Paysasa\Payments;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Paysasa\Payments\Actions\RunFraudChecks;
use Paysasa\Payments\Console\Commands\InstallCommand;
use Paysasa\Payments\Console\Commands\RegisterMpesaUrlsCommand;
use Paysasa\Payments\Console\Commands\StatusCommand;
use Paysasa\Payments\Contracts\PaymentRepository;
use Paysasa\Payments\Listeners\PaymentEventSubscriber;
use Paysasa\Payments\Managers\PaymentManager;
use Paysasa\Payments\Support\AuditLogger;
use Paysasa\Payments\Support\CredentialVault;
use Paysasa\Payments\Support\EloquentPaymentRepository;
use Paysasa\Payments\Support\IdempotencyManager;
use Paysasa\Payments\Support\PaymentLogger;
use Paysasa\Payments\Webhooks\WebhookDispatcher;

/**
 * The single wiring point for the whole package. Every binding here
 * follows Laravel's own conventions deliberately (config merging,
 * publishable tags, conditional console registration) so the package
 * feels native rather than bolted on. See Documentation/01-installation.md
 * for what each publish tag produces.
 */
class PaysasaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/paysasa.php', 'paysasa');

        $this->app->bind(PaymentRepository::class, EloquentPaymentRepository::class);

        $this->app->singleton(CredentialVault::class, fn ($app) => new CredentialVault($app['config']->get('paysasa')));

        $this->app->singleton(PaymentLogger::class, fn ($app) => new PaymentLogger($app['config']->get('paysasa.logging', [])));

        $this->app->singleton(IdempotencyManager::class, fn ($app) => new IdempotencyManager($app['config']->get('paysasa.idempotency', [])));

        $this->app->singleton(RunFraudChecks::class, fn ($app) => new RunFraudChecks($app['config']->get('paysasa.fraud_checks', [])));

        $this->app->singleton(AuditLogger::class);

        $this->app->singleton(WebhookDispatcher::class, fn ($app) => new WebhookDispatcher($app['config']->get('paysasa')));

        $this->app->singleton(PaymentManager::class, fn ($app) => new PaymentManager($app));
        $this->app->alias(PaymentManager::class, 'paysasa.manager');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/paysasa.php' => config_path('paysasa.php')], 'paysasa-config');

        $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'paysasa-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerRoutes();

        Event::subscribe(PaymentEventSubscriber::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                StatusCommand::class,
                RegisterMpesaUrlsCommand::class,
            ]);
        }
    }

    protected function registerRoutes(): void
    {
        if (! config('paysasa.routes.enabled', true)) {
            return;
        }

        Route::prefix(config('paysasa.webhook_route_prefix', 'paysasa/webhooks'))
            ->middleware(config('paysasa.routes.middleware', ['api']))
            ->domain(config('paysasa.routes.domain'))
            ->group(__DIR__.'/../routes/paysasa.php');
    }
}
