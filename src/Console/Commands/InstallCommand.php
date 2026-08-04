<?php

declare(strict_types=1);

namespace Paysasa\Payments\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'paysasa:install {--migrate : Run the new migrations immediately}';

    protected $description = 'Publish the Paysasa config and migrations, and optionally run them';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'paysasa-config']);
        $this->call('vendor:publish', ['--tag' => 'paysasa-migrations']);

        $this->info('Published config/paysasa.php and database migrations.');

        if ($this->option('migrate')) {
            $this->call('migrate');
        } else {
            $this->comment('Run `php artisan migrate` when ready, then set your provider credentials in .env.');
        }

        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Set MPESA_*, STRIPE_*, ... credentials in .env for the providers you use.');
        $this->line('  2. Point each provider\'s callback/webhook URL at {APP_URL}/paysasa/webhooks/{provider}.');
        $this->line('  3. Run `php artisan paysasa:status` to verify every configured driver.');

        return self::SUCCESS;
    }
}
