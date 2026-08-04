<?php

declare(strict_types=1);

namespace Paysasa\Payments\Console\Commands;

use Illuminate\Console\Command;
use Paysasa\Payments\Managers\PaymentManager;

/**
 * Sanity-checks every driver listed in config('paysasa.drivers') by
 * attempting to resolve it through the container (which runs each
 * driver's requireConfig() checks) — a fast way to catch a missing env var
 * before it surfaces as a failed charge in production.
 */
class StatusCommand extends Command
{
    protected $signature = 'paysasa:status';

    protected $description = 'Check that every configured payment driver has the credentials it needs';

    public function handle(PaymentManager $manager): int
    {
        $drivers = array_keys(config('paysasa.drivers', []));
        $rows = [];
        $hasFailure = false;

        foreach ($drivers as $name) {
            try {
                $manager->driverInstance($name);
                $rows[] = [$name, '<fg=green>OK</>', ''];
            } catch (\Throwable $e) {
                $hasFailure = true;
                $rows[] = [$name, '<fg=red>MISCONFIGURED</>', $e->getMessage()];
            }
        }

        $this->table(['Driver', 'Status', 'Detail'], $rows);

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }
}
