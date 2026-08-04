<?php

declare(strict_types=1);

namespace Paysasa\Payments\Console\Commands;

use Illuminate\Console\Command;
use Paysasa\Payments\Services\Mpesa\DarajaAuthenticator;
use Paysasa\Payments\Services\Mpesa\DarajaClient;

/**
 * One-time (per shortcode) setup command: registers your C2B validation
 * and confirmation URLs with Daraja. Safaricom requires this be done
 * before C2B payments to your shortcode will trigger callbacks — see
 * Documentation/06-webhook-guide.md#mpesa-c2b-url-registration.
 */
class RegisterMpesaUrlsCommand extends Command
{
    protected $signature = 'paysasa:mpesa:register-urls {--response-type=Completed}';

    protected $description = 'Register the M-Pesa C2B validation and confirmation URLs with Daraja';

    public function handle(): int
    {
        $config = config('paysasa.drivers.mpesa');

        foreach (['consumer_key', 'consumer_secret', 'shortcode', 'c2b_validation_url', 'c2b_confirmation_url'] as $key) {
            if (blank($config[$key] ?? null)) {
                $this->error("Missing config('paysasa.drivers.mpesa.{$key}') — set it in .env first.");

                return self::FAILURE;
            }
        }

        $env = $config['env'] ?? 'sandbox';
        $baseUrl = $config['base_urls'][$env];

        $auth = new DarajaAuthenticator($baseUrl, $config['consumer_key'], $config['consumer_secret']);
        $client = new DarajaClient($baseUrl, $auth);

        $result = $client->registerC2bUrls([
            'shortcode' => $config['shortcode'],
            'response_type' => $this->option('response-type'),
            'validation_url' => $config['c2b_validation_url'],
            'confirmation_url' => $config['c2b_confirmation_url'],
        ]);

        $this->info('Registered: '.($result['ResponseDescription'] ?? json_encode($result)));

        return self::SUCCESS;
    }
}
