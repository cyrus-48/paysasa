<?php

declare(strict_types=1);

namespace Paysasa\Payments\Support\Doctor;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Exceptions\DriverNotFoundException;
use Paysasa\Payments\Exceptions\ProviderApiException;
use Paysasa\Payments\Exceptions\WebhookVerificationException;
use Paysasa\Payments\Managers\PaymentManager;
use Paysasa\Payments\Models\Payment;
use Paysasa\Payments\Models\PaymentAttempt;
use Paysasa\Payments\Models\Refund;
use Paysasa\Payments\Models\Transaction;
use Paysasa\Payments\Models\Webhook;
use Paysasa\Payments\Services\Airtel\AirtelAuthenticator;
use Paysasa\Payments\Services\Mpesa\DarajaAuthenticator;
use Paysasa\Payments\Webhooks\WebhookDispatcher;

/**
 * Diagnoses the misconfigurations behind most "it doesn't work" reports:
 * missing or mismatched credentials, callback URLs a provider cannot reach,
 * webhook verification that cannot succeed, and credentials the provider
 * rejects. Read-only: it never creates payments or webhook rows (the only
 * writes are fresh OAuth tokens cached the way normal use would cache them).
 */
class Doctor
{
    /**
     * What each built-in driver needs.
     *
     * credentials: any of these being set means the driver is "in use" and gets checked.
     * required:    needed to take payments; missing => failure.
     * recommended: missing => warning.
     * urls:        config keys holding a callback URL the provider will call.
     * auth:        which live credential check applies (null = none available).
     *
     * @var array<string, array{credentials: list<string>, required: list<string>, recommended: list<string>, urls: list<string>, auth: string|null}>
     */
    private const PROFILES = [
        'mpesa' => [
            'credentials' => ['consumer_key', 'consumer_secret', 'shortcode', 'passkey'],
            'required' => ['consumer_key', 'consumer_secret', 'shortcode', 'passkey'],
            'recommended' => ['stk_callback_url'],
            'urls' => ['stk_callback_url', 'c2b_validation_url', 'c2b_confirmation_url', 'b2c_result_url', 'b2c_queue_timeout_url'],
            'auth' => 'daraja',
        ],
        'airtel' => [
            'credentials' => ['client_id', 'client_secret'],
            'required' => ['client_id', 'client_secret'],
            'recommended' => ['callback_url'],
            'urls' => ['callback_url'],
            'auth' => 'airtel',
        ],
        'stripe' => [
            'credentials' => ['secret_key', 'public_key', 'webhook_secret'],
            'required' => ['secret_key'],
            'recommended' => [],
            'urls' => [],
            'auth' => 'stripe',
        ],
        'pesapal' => [
            'credentials' => ['consumer_key', 'consumer_secret'],
            'required' => ['consumer_key', 'consumer_secret', 'ipn_url'],
            'recommended' => [],
            'urls' => ['ipn_url'],
            'auth' => 'pesapal',
        ],
        'flutterwave' => [
            'credentials' => ['secret_key', 'public_key'],
            'required' => ['secret_key'],
            'recommended' => [],
            'urls' => [],
            'auth' => 'flutterwave',
        ],
        'pesalink' => [
            'credentials' => ['institution_code', 'api_key', 'api_secret'],
            'required' => ['institution_code', 'api_key', 'api_secret'],
            'recommended' => [],
            'urls' => [],
            'auth' => null,
        ],
    ];

    /** The well-known public Daraja sandbox values; they never work against production. */
    private const MPESA_SANDBOX_SHORTCODE = '174379';
    private const MPESA_SANDBOX_PASSKEY = 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919';

    /** Set by environmentChecks(); the per-driver DB lookups are skipped when the schema is not usable. */
    private bool $databaseReady = false;

    public function __construct(
        private readonly PaymentManager $manager,
        private readonly WebhookDispatcher $webhooks,
    ) {
    }

    /**
     * @param list<string> $only    limit to these drivers (default: every driver that has credentials, plus the default driver)
     * @param bool         $network contact the providers (live credential check)
     * @param bool         $probe   ping your own callback URLs (needs $network)
     *
     * @return list<CheckResult>
     */
    public function run(array $only = [], bool $network = true, bool $probe = true): array
    {
        $results = $this->environmentChecks();

        /** @var array<string, array<string, mixed>> $drivers */
        $drivers = (array) config('paysasa.drivers', []);
        $default = (string) config('paysasa.default');

        foreach ($only as $name) {
            if (! isset($drivers[$name])) {
                $results[] = $this->fail($name, 'driver', "Driver [{$name}] is not defined in config('paysasa.drivers').", 'Check the spelling, or publish the config with `php artisan vendor:publish --tag=paysasa-config`.');
            }
        }

        foreach ($drivers as $name => $config) {
            $name = (string) $name;

            if ($only !== [] && ! in_array($name, $only, true)) {
                continue;
            }

            if ($only === [] && ! $this->isInUse($name, $config)) {
                $results[] = $name === $default
                    ? $this->warn($name, 'driver', "[{$name}] is the default driver (PAYSASA_DEFAULT_DRIVER) but has no credentials.", 'Payment::amount(...)->charge() without ->driver() will fail. Set PAYSASA_DEFAULT_DRIVER to a driver you use, or add credentials.')
                    : new CheckResult($name, 'driver', Status::Skip, 'Not configured (no credentials set), skipped.');

                continue;
            }

            array_push($results, ...$this->driverChecks($name, $config, $network, $probe));
        }

        return $results;
    }

    // ------------------------------------------------------------------ environment

    /** @return list<CheckResult> */
    private function environmentChecks(): array
    {
        $g = 'environment';
        $production = $this->appIsProduction();
        $results = [];

        // Tables
        $missing = [];
        $tablesOk = false;

        try {
            foreach ([Payment::class, PaymentAttempt::class, Transaction::class, Refund::class, Webhook::class] as $model) {
                $table = (new $model())->getTable();

                if (! Schema::hasTable($table)) {
                    $missing[] = $table;
                }
            }

            $tablesOk = $missing === [];
            $results[] = $tablesOk
                ? $this->pass($g, 'database', 'Paysasa tables exist.')
                : $this->fail($g, 'database', 'Missing tables: '.implode(', ', $missing).'.', 'Run `php artisan migrate` (or publish the migrations with the paysasa-migrations tag).');
        } catch (\Throwable $e) {
            $results[] = $this->fail($g, 'database', 'Cannot query the database: '.$this->firstLine($e->getMessage()), 'Check DB_* settings and that the database is reachable.');
        }

        $this->databaseReady = $tablesOk;

        // Routes
        if (! (bool) config('paysasa.routes.enabled', true)) {
            $results[] = $this->warn($g, 'webhook routes', 'Paysasa webhook routes are disabled (paysasa.routes.enabled = false).', 'Providers will have nothing to call unless you register your own route to WebhookController.');
        } elseif (! Route::has('paysasa.webhooks.handle')) {
            $results[] = $this->fail($g, 'webhook routes', 'The paysasa.webhooks.handle route is not registered.', 'If routes are cached, run `php artisan route:clear` then `route:cache` again.');
        } else {
            $results[] = $this->pass($g, 'webhook routes', 'Webhook route registered at /'.$this->routePrefix().'/{provider}.');
        }

        // Callback base URL
        $base = (string) config('paysasa.callback_base_url', '');

        if ($base === '') {
            $results[] = $this->warn($g, 'callback base URL', 'No callback base URL is set.', 'Set PAYSASA_CALLBACK_URL (or APP_URL) so callback URLs can be derived.');
        } else {
            $problem = $this->urlProblem($base, $production);
            $results[] = $problem === null
                ? $this->pass($g, 'callback base URL', $base)
                : new CheckResult($g, 'callback base URL', $problem[0], "{$base}: {$problem[1]}", $problem[2]);
        }

        // Queue
        $connection = (string) (config('paysasa.queue.connection') ?: config('queue.default'));
        $queues = config('paysasa.queue.webhooks_queue', 'payment-webhooks').','.config('paysasa.queue.queue', 'payments');

        $results[] = match (true) {
            $connection === 'null' => $this->fail($g, 'queue', 'Queue connection is "null": webhook and verification jobs are silently discarded.', 'Set PAYSASA_QUEUE_CONNECTION (or QUEUE_CONNECTION) to a real driver.'),
            $connection === 'sync' && $production => $this->warn($g, 'queue', 'Queue connection is "sync" in production: jobs run inside the webhook request and cannot retry with backoff.', 'Use redis/database/sqs and run `php artisan queue:work --queue='.$queues.'`.'),
            $connection === 'sync' => $this->pass($g, 'queue', 'Queue connection is "sync" (fine for local development).'),
            default => $this->pass($g, 'queue', "Queue connection is \"{$connection}\". Make sure a worker runs: queue:work --queue={$queues}"),
        };

        // Cache (idempotency keys and OAuth tokens live here)
        $stores = array_unique([
            (string) config('cache.default'),
            (string) config('paysasa.idempotency.store', ''),
        ]);

        $badStore = null;

        foreach ($stores as $store) {
            if (in_array($store, ['array', 'null'], true)) {
                $badStore = $store;
            }
        }

        $results[] = ($badStore !== null && $production)
            ? $this->fail($g, 'cache', "Cache store \"{$badStore}\" does not persist between requests: idempotency keys and OAuth tokens are lost, so retries can double-charge.", 'Use redis, database, file or memcached (CACHE_STORE / PAYSASA_IDEMPOTENCY_STORE).')
            : $this->pass($g, 'cache', 'Cache store persists between requests.');

        // TLS
        if ($production && ! (bool) config('paysasa.http.verify_ssl', true)) {
            $results[] = $this->fail($g, 'tls', 'TLS certificate verification is disabled (PAYSASA_HTTP_VERIFY_SSL=false) in production.', 'Remove the override; payment API calls must verify certificates.');
        }

        // Scheduler
        $results[] = (bool) config('paysasa.schedule.enabled', true)
            ? $this->pass($g, 'scheduler', 'paysasa:reconcile is scheduled every minute (requires `schedule:run` in cron, or `schedule:work`).')
            : $this->warn($g, 'scheduler', 'The package scheduler is disabled (paysasa.schedule.enabled = false).', 'Schedule `paysasa:reconcile` yourself, or stuck pending payments will never be resolved.');

        // Stuck payments: the symptom of a dead queue worker or scheduler.
        if ($tablesOk) {
            $ttl = max(1, (int) config('paysasa.pending_ttl_minutes', 60));
            $stuck = Payment::query()
                ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value])
                ->where('created_at', '<', now()->subMinutes($ttl * 2))
                ->count();

            $results[] = $stuck > 0
                ? $this->warn($g, 'stuck payments', "{$stuck} payment(s) have been pending for more than ".($ttl * 2).' minutes.', 'The queue worker or scheduler is probably not running, or callbacks are not arriving. Try `php artisan paysasa:reconcile`.')
                : $this->pass($g, 'stuck payments', 'No payments stuck in pending.');
        }

        return $results;
    }

    // ------------------------------------------------------------------ per driver

    /**
     * @param array<string, mixed> $config
     *
     * @return list<CheckResult>
     */
    private function driverChecks(string $name, array $config, bool $network, bool $probe): array
    {
        $profile = self::PROFILES[$name] ?? null;
        $results = [];

        // Can the driver class be built at all?
        try {
            $this->manager->driverInstance($name);
        } catch (\Throwable $e) {
            $results[] = $this->fail($name, 'driver', 'Driver could not be resolved: '.$e->getMessage(), 'Check the `driver` class in config/paysasa.php.');

            return $results;
        }

        if ($profile === null) {
            $results[] = $this->pass($name, 'driver', 'Custom driver resolves. No built-in checks exist for it.');

            return $results;
        }

        // Credentials
        $missing = array_values(array_filter($profile['required'], fn (string $key) => blank($config[$key] ?? null)));
        $configOk = $missing === [];

        $results[] = $configOk
            ? $this->pass($name, 'credentials', 'All required settings are present.')
            : $this->fail($name, 'credentials', 'Missing required settings: '.implode(', ', $missing).'.', 'Set the matching env vars; see Documentation/02-configuration.md.');

        $recommended = array_values(array_filter($profile['recommended'], fn (string $key) => blank($config[$key] ?? null)));

        if ($recommended !== []) {
            $results[] = $this->warn($name, 'recommended settings', 'Not set: '.implode(', ', $recommended).'.', 'Charges that need a callback URL must then pass one on every request.');
        }

        // Mode (sandbox / production)
        array_push($results, ...$this->modeChecks($name, $config));

        // Provider-specific sanity checks on the values themselves
        array_push($results, ...$this->valueChecks($name, $config));

        // Callback URLs
        $urls = $this->callbackUrls($name, $config, $profile['urls']);
        array_push($results, ...$this->urlChecks($name, $config, $urls));

        // Webhook verification (can a real callback ever pass?)
        $results[] = $this->webhookVerifierCheck($name);

        // Live checks
        if (! $network) {
            $results[] = new CheckResult($name, 'credential check', Status::Skip, 'Skipped (--offline).');
        } elseif ($profile['auth'] === null) {
            $results[] = new CheckResult($name, 'credential check', Status::Skip, 'No live credential check is available for this provider.');
        } elseif (! $configOk) {
            $results[] = new CheckResult($name, 'credential check', Status::Skip, 'Skipped until the missing settings are set.');
        } else {
            $results[] = $this->authCheck($name, $profile['auth'], $config);
        }

        if ($network && $probe) {
            array_push($results, ...$this->probeChecks($name, $urls));
        }

        // What webhooks have actually done recently
        $recent = $this->recentWebhookCheck($name);

        if ($recent !== null) {
            $results[] = $recent;
        }

        return $results;
    }

    /** @param array<string, mixed> $config */
    private function isInUse(string $name, array $config): bool
    {
        $keys = self::PROFILES[$name]['credentials'] ?? ['api_key', 'secret_key', 'client_id', 'consumer_key'];

        foreach ($keys as $key) {
            if (! blank($config[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<CheckResult>
     */
    private function modeChecks(string $name, array $config): array
    {
        if (! isset($config['env'])) {
            return [];
        }

        $mode = (string) $config['env'];
        $production = $this->appIsProduction();
        $results = [];

        $base = $this->baseUrl($config);

        if ($base === '') {
            $results[] = $this->fail($name, 'base URL', "No base URL is configured for mode \"{$mode}\".", "Set drivers.{$name}.base_urls.{$mode} (the PESALINK_*_URL env vars for PesaLink).");
        }

        if ($production && $mode !== 'production') {
            $results[] = $this->warn($name, 'mode', "Driver is in \"{$mode}\" mode while the app runs in production: no real money moves.", strtoupper($name).'_ENV=production when you are ready to go live.');
        } elseif (! $production && $mode === 'production') {
            $results[] = $this->warn($name, 'mode', "Driver is in \"production\" mode but the app is not (APP_ENV=".app()->environment().'): this will use live credentials and real money.', 'Use sandbox credentials locally and in staging.');
        } else {
            $results[] = $this->pass($name, 'mode', "Mode: {$mode}.");
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<CheckResult>
     */
    private function valueChecks(string $name, array $config): array
    {
        $production = $this->appIsProduction();
        $results = [];

        if ($name === 'stripe') {
            $secret = (string) ($config['secret_key'] ?? '');
            $public = (string) ($config['public_key'] ?? '');
            $whsec = (string) ($config['webhook_secret'] ?? '');

            if ($secret !== '') {
                $live = str_starts_with($secret, 'sk_live_') || str_starts_with($secret, 'rk_live_');
                $test = str_starts_with($secret, 'sk_test_') || str_starts_with($secret, 'rk_test_');

                if (! $live && ! $test) {
                    $results[] = $this->warn($name, 'secret key', 'STRIPE_SECRET_KEY does not look like a Stripe secret key (expected sk_live_… or sk_test_…).');
                } elseif ($production && $test) {
                    $results[] = $this->fail($name, 'secret key', 'A Stripe TEST key is configured in production: no real payments will be taken.', 'Use the sk_live_… key from the Stripe dashboard.');
                } elseif (! $production && $live) {
                    $results[] = $this->warn($name, 'secret key', 'A Stripe LIVE key is configured outside production: real cards will be charged.', 'Use sk_test_… locally and in staging.');
                } else {
                    $results[] = $this->pass($name, 'secret key', $live ? 'Live-mode key.' : 'Test-mode key.');
                }

                if ($public !== '' && ($live !== str_starts_with($public, 'pk_live_'))) {
                    $results[] = $this->warn($name, 'public key', 'The public key and secret key are from different modes (live vs test).');
                }
            }

            if ($whsec !== '' && ! str_starts_with($whsec, 'whsec_')) {
                $results[] = $this->warn($name, 'webhook secret', 'STRIPE_WEBHOOK_SECRET should start with "whsec_" (the endpoint signing secret, not an API key).');
            }
        }

        if ($name === 'mpesa') {
            if ($production && (string) ($config['env'] ?? '') === 'production') {
                if ((string) ($config['shortcode'] ?? '') === self::MPESA_SANDBOX_SHORTCODE) {
                    $results[] = $this->fail($name, 'shortcode', 'The Daraja sandbox shortcode (174379) is configured for production.', 'Use your own paybill/till shortcode.');
                }

                if (($config['passkey'] ?? '') === self::MPESA_SANDBOX_PASSKEY) {
                    $results[] = $this->fail($name, 'passkey', 'The public Daraja sandbox passkey is configured for production.', 'Use the passkey Safaricom issued for your shortcode.');
                }
            }

            $certificate = (string) ($config['certificate_path'] ?? '');

            if ($certificate !== '' && ! is_readable($certificate)) {
                $results[] = $this->fail($name, 'certificate', "MPESA_CERTIFICATE_PATH is not readable: {$certificate}", 'B2C and reversals need Safaricom\'s certificate to encrypt the initiator password.');
            }
        }

        return $results;
    }

    // ------------------------------------------------------------------ callback URLs

    /**
     * @param array<string, mixed> $config
     * @param list<string>         $urlKeys
     *
     * @return array<string, string> label => URL (configured URLs, or the derived one to register in a dashboard)
     */
    private function callbackUrls(string $name, array $config, array $urlKeys): array
    {
        $urls = [];

        foreach ($urlKeys as $key) {
            $value = (string) ($config[$key] ?? '');

            if ($value !== '') {
                $urls[$key] = $value;
            }
        }

        if ($urls === []) {
            $base = rtrim((string) config('paysasa.callback_base_url', ''), '/');

            if ($base !== '') {
                $aliases = array_keys(array_filter((array) config('paysasa.webhook_aliases', []), fn ($provider) => $provider === $name));
                $segment = (string) ($aliases[0] ?? $name);
                $urls['webhook URL to register'] = $base.'/'.$this->routePrefix().'/'.$segment;
            }
        }

        return $urls;
    }

    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $urls
     *
     * @return list<CheckResult>
     */
    private function urlChecks(string $name, array $config, array $urls): array
    {
        $production = $this->appIsProduction();
        $baseHost = strtolower((string) parse_url((string) config('paysasa.callback_base_url', ''), PHP_URL_HOST));
        $allowedHosts = array_map('strtolower', (array) ($config['allowed_callback_hosts'] ?? []));
        $hasAllowlist = ! empty($config['webhook_ip_allowlist']);
        $results = [];

        foreach ($urls as $label => $url) {
            $check = "URL ({$label})";
            $derived = $label === 'webhook URL to register';

            $problem = $this->urlProblem($url, $production);

            if ($problem !== null) {
                $results[] = new CheckResult($name, $check, $problem[0], "{$url}: {$problem[1]}", $problem[2]);

                continue;
            }

            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            if ($allowedHosts !== [] && ! in_array($host, $allowedHosts, true)) {
                $results[] = $this->fail($name, $check, "{$url}: host [{$host}] is not in allowed_callback_hosts, so the driver will refuse it.", 'Add the host to allowed_callback_hosts or fix the URL.');

                continue;
            }

            if ($derived) {
                $results[] = $this->pass($name, $check, "Register this URL in the {$name} dashboard: {$url}");

                continue;
            }

            // Does the path point at the Paysasa route (or its opaque alias)?
            $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
            $prefix = $this->routePrefix();
            $aliases = array_keys(array_filter((array) config('paysasa.webhook_aliases', []), fn ($provider) => $provider === $name));
            $usesAlias = false;
            $usesPlain = str_ends_with($path, "{$prefix}/{$name}");

            foreach ($aliases as $alias) {
                if (str_ends_with($path, "{$prefix}/{$alias}")) {
                    $usesAlias = true;
                }
            }

            if (! $usesAlias && ! $usesPlain) {
                $results[] = $this->warn($name, $check, "{$url} does not end in /{$prefix}/{$name} (or an opaque alias), so it will not reach Paysasa's webhook controller.", 'Fine if your own route forwards to it; otherwise fix the URL.');
            } elseif ($usesPlain && $aliases !== [] && ! $hasAllowlist) {
                $results[] = new CheckResult(
                    $name,
                    $check,
                    $production ? Status::Fail : Status::Warn,
                    "{$url} uses the guessable route although an opaque alias is configured.",
                    'Register the alias URL instead; the plain route is rejected in production without an IP allowlist.',
                );
            } elseif ($baseHost !== '' && $host !== $baseHost) {
                $results[] = $this->warn($name, $check, "{$url}: host differs from the callback base URL host [{$baseHost}].", 'Fine if intentional (separate webhook domain).');
            } else {
                $results[] = $this->pass($name, $check, $url);
            }
        }

        return $results;
    }

    /**
     * @return array{0: Status, 1: string, 2: string|null}|null null when the URL looks usable
     */
    private function urlProblem(string $url, bool $production): ?array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return [Status::Fail, 'not a valid absolute http(s) URL.', 'Use a full URL such as https://example.com/paysasa/webhooks/mpesa.'];
        }

        $local = $this->isLocalHost($parts['host']);

        if ($local) {
            return $production
                ? [Status::Fail, "host [{$parts['host']}] is a local/private address that providers cannot reach.", 'Use your public HTTPS domain.']
                : [Status::Warn, "host [{$parts['host']}] is local, so providers cannot call it.", 'Use a tunnel (ngrok, Expose, Cloudflare Tunnel) when testing callbacks from a sandbox.'];
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return $production
                ? [Status::Fail, 'uses http:// but providers require HTTPS callbacks.', 'Serve the callback over HTTPS.']
                : [Status::Warn, 'uses http://; providers require HTTPS callbacks.', 'Use an https:// URL.'];
        }

        return null;
    }

    private function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        if (! str_contains($host, '.')) {
            return true; // "localhost", docker service names, ...
        }

        foreach (['.localhost', '.test', '.local', '.localdomain', '.internal'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ webhook verification

    /**
     * Runs the real verifier on an unsigned, empty request. A verifier that is
     * set up correctly rejects it (invalid signature); one that fails closed
     * for configuration reasons says so with a specific reason.
     */
    private function webhookVerifierCheck(string $name): CheckResult
    {
        $provider = PaymentProvider::tryFrom($name);

        if ($provider === null) {
            return new CheckResult($name, 'webhook verification', Status::Skip, 'No built-in webhook verifier for this driver.');
        }

        $opaque = in_array($name, array_values((array) config('paysasa.webhook_aliases', [])), true);

        try {
            $this->webhooks->verifierFor($name, $opaque)->verify(new WebhookPayload($provider, [], '', []));
        } catch (WebhookVerificationException $e) {
            return match ($e->reason()) {
                WebhookVerificationException::MISSING_SECRET => $this->fail($name, 'webhook verification', 'No webhook signing secret is configured, so every webhook is rejected with 401.', 'Set the provider\'s webhook secret (e.g. STRIPE_WEBHOOK_SECRET, FLUTTERWAVE_WEBHOOK_SECRET_HASH, PESALINK_WEBHOOK_SECRET).'),
                WebhookVerificationException::MISCONFIGURED => $this->fail($name, 'webhook verification', 'In production, callbacks are rejected: this provider\'s callbacks are unsigned and neither an IP allowlist nor a secret route alias is configured.', 'Set '.strtoupper($name).'_WEBHOOK_IP_ALLOWLIST and/or PAYSASA_'.strtoupper($name).'_WEBHOOK_ALIAS and register the alias URL with the provider.'),
                default => $this->pass($name, 'webhook verification', 'Verifier is active and rejects unsigned requests.'),
            };
        } catch (DriverNotFoundException) {
            return new CheckResult($name, 'webhook verification', Status::Skip, 'No webhook verifier registered for this driver.');
        }

        return $this->pass($name, 'webhook verification', 'Verifier accepts requests without a signature: authenticity rests on a secret route alias and/or confirmation with the provider.');
    }

    // ------------------------------------------------------------------ live checks

    /** @param array<string, mixed> $config */
    private function authCheck(string $name, string $auth, array $config): CheckResult
    {
        $check = 'credential check';
        $base = $this->baseUrl($config);
        $host = (string) (parse_url($base, PHP_URL_HOST) ?: 'the provider');

        try {
            switch ($auth) {
                case 'daraja':
                    (new DarajaAuthenticator($base, (string) $config['consumer_key'], (string) $config['consumer_secret']))->forceRefresh();

                    return $this->pass($name, $check, "Daraja accepted the consumer key/secret ({$host}).");

                case 'airtel':
                    (new AirtelAuthenticator($base, (string) $config['client_id'], (string) $config['client_secret']))->forceRefresh();

                    return $this->pass($name, $check, "Airtel accepted the client id/secret ({$host}).");

                case 'pesapal':
                    $response = Http::baseUrl($base)->timeout(15)->post('/api/Auth/RequestToken', [
                        'consumer_key' => $config['consumer_key'],
                        'consumer_secret' => $config['consumer_secret'],
                    ]);

                    if ($response->failed() || blank($response->json('token'))) {
                        throw new ProviderApiException('Pesapal did not return a token: HTTP '.$response->status(), $response->status() >= 400 ? $response->status() : 401, $response->json());
                    }

                    return $this->pass($name, $check, "Pesapal accepted the consumer key/secret ({$host}).");

                case 'stripe':
                    $response = Http::baseUrl('https://api.stripe.com/v1')->withToken((string) $config['secret_key'])->timeout(15)->get('/balance');

                    if ($response->failed()) {
                        throw new ProviderApiException('Stripe rejected the API key: HTTP '.$response->status(), $response->status(), $response->json());
                    }

                    $livemode = $response->json('livemode');

                    if ($this->appIsProduction() && $livemode === false) {
                        return $this->fail($name, $check, 'Stripe accepted the key, but it is a TEST-mode key and the app is in production.', 'Use the live secret key.');
                    }

                    return $this->pass($name, $check, 'Stripe accepted the API key'.($livemode === true ? ' (live mode).' : ($livemode === false ? ' (test mode).' : '.')));

                case 'flutterwave':
                    $response = Http::baseUrl((string) ($config['base_url'] ?? 'https://api.flutterwave.com/v3'))->withToken((string) $config['secret_key'])->acceptJson()->timeout(15)->get('/balances');

                    if ($response->failed()) {
                        throw new ProviderApiException('Flutterwave rejected the secret key: HTTP '.$response->status(), $response->status(), $response->json());
                    }

                    return $this->pass($name, $check, 'Flutterwave accepted the secret key.');
            }
        } catch (ProviderApiException $e) {
            $status = $e->statusCode();

            if (in_array($status, [400, 401, 403], true)) {
                return $this->fail($name, $check, "The provider rejected the credentials (HTTP {$status}).", 'Check that the key/secret pair belongs to this environment: sandbox keys do not work against production URLs, and vice versa.');
            }

            return $this->fail($name, $check, "The provider answered with an error (HTTP {$status}): ".$e->getMessage(), 'Often temporary; retry shortly. Persistent 5xx means a provider outage or a wrong base URL.');
        } catch (ConnectionException $e) {
            return $this->fail($name, $check, "Could not reach {$host}: ".$this->firstLine($e->getMessage()), 'Check DNS, the server\'s outbound firewall/proxy, and that api hosts are allow-listed for outbound traffic.');
        } catch (\Throwable $e) {
            return $this->fail($name, $check, 'Unexpected error: '.$this->firstLine($e->getMessage()));
        }

        return new CheckResult($name, $check, Status::Skip, 'No live credential check is available for this provider.');
    }

    /**
     * Pings your own callback URLs through the public internet. This proves
     * the URL serves the Paysasa route from this server's point of view (DNS,
     * TLS, proxy, WAF, route cache); it cannot prove that the provider's
     * servers can reach it, so an IP-restricted firewall still needs a manual
     * check against the provider's published ranges.
     *
     * @param array<string, string> $urls
     *
     * @return list<CheckResult>
     */
    private function probeChecks(string $name, array $urls): array
    {
        $results = [];
        $prefix = $this->routePrefix();

        foreach ($urls as $label => $url) {
            $check = "reachability ({$label})";
            $parts = parse_url($url);

            if ($parts === false || ! isset($parts['host']) || $this->isLocalHost($parts['host'])) {
                $results[] = new CheckResult($name, $check, Status::Skip, 'Skipped: local address, not probed.');

                continue;
            }

            $path = trim((string) ($parts['path'] ?? ''), '/');
            $segment = basename($path);

            if (! str_ends_with($path, "{$prefix}/{$segment}") || $segment === '') {
                $results[] = new CheckResult($name, $check, Status::Skip, 'Skipped: not a Paysasa webhook route, so it cannot answer the ping.');

                continue;
            }

            $probeUrl = explode('?', $url, 2)[0];

            try {
                $response = Http::timeout(10)->connectTimeout(5)->withHeaders([Ping::HEADER => Ping::token($segment)])->acceptJson()->get($probeUrl);
            } catch (ConnectionException $e) {
                $results[] = $this->fail($name, $check, "Could not connect to {$parts['host']}: ".$this->firstLine($e->getMessage()), 'Check the DNS record, TLS certificate, and that the app is served on this URL.');

                continue;
            }

            $results[] = match (true) {
                $response->ok() && $response->json('paysasa_doctor') === true => $this->pass($name, $check, "{$probeUrl} reached the Paysasa webhook route."),
                $response->status() === 403 && $response->json('message') === 'Invalid doctor token' => $this->warn($name, $check, 'The URL reached a Paysasa route, but it did not accept the ping token.', 'The server behind this URL has a different APP_KEY or an older deploy of this package.'),
                $response->status() === 404 => $this->fail($name, $check, "{$probeUrl} answered 404: the webhook route is not served here.", 'Check paysasa.routes.enabled, webhook_route_prefix, the routes domain, and clear any route cache.'),
                $response->status() === 503 => $this->warn($name, $check, 'The app answered 503 (maintenance mode or overloaded).', 'Callbacks sent now would fail; bring the app up first.'),
                $response->serverError() => $this->fail($name, $check, "{$probeUrl} answered HTTP {$response->status()}.", 'Check the application logs.'),
                $response->status() === 401 || $response->status() === 403 || $response->status() === 419 => $this->warn($name, $check, "{$probeUrl} answered HTTP {$response->status()} before reaching Paysasa.", 'A firewall, WAF, auth middleware or CSRF protection may be blocking callbacks. The webhook route must be public and outside the web/CSRF group.'),
                default => $this->warn($name, $check, "{$probeUrl} answered HTTP {$response->status()}, but not with Paysasa's response.", 'Something else may be serving this URL (a CDN error page, a different app, a redirect).'),
            };
        }

        return $results;
    }

    private function recentWebhookCheck(string $name): ?CheckResult
    {
        if (! $this->databaseReady) {
            return null;
        }

        /** @var array<string, int|string> $counts */
        $counts = Webhook::query()
            ->toBase()
            ->where('provider', $name)
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $total = (int) array_sum($counts);
        $failed = (int) ($counts['verification_failed'] ?? 0);

        if ($total === 0) {
            return new CheckResult($name, 'recent webhooks', Status::Skip, 'No webhooks received in the last 24 hours.');
        }

        if ($failed === 0) {
            return $this->pass($name, 'recent webhooks', "{$total} webhook(s) received in the last 24 hours, none failed verification.");
        }

        return $failed === $total
            ? $this->warn($name, 'recent webhooks', "All {$total} webhook(s) in the last 24 hours failed verification.", 'Wrong or missing signing secret, an IP allowlist that excludes the provider, or a callback registered on the wrong route. Inspect with `php artisan paysasa:webhooks:unmatched` and the webhooks table (status = verification_failed).')
            : $this->warn($name, 'recent webhooks', "{$failed} of {$total} webhook(s) in the last 24 hours failed verification.", 'Some callbacks are being rejected; check the webhooks table for the error_message and ip_address of the rejected ones.');
    }

    // ------------------------------------------------------------------ helpers

    private function appIsProduction(): bool
    {
        return app()->environment('production');
    }

    private function routePrefix(): string
    {
        return trim((string) config('paysasa.webhook_route_prefix', 'paysasa/webhooks'), '/');
    }

    /** @param array<string, mixed> $config */
    private function baseUrl(array $config): string
    {
        $urls = $config['base_urls'] ?? null;

        if (is_array($urls)) {
            $mode = (string) ($config['env'] ?? config('paysasa.environment', 'sandbox'));

            return (string) ($urls[$mode === 'production' ? 'production' : 'sandbox'] ?? '');
        }

        return (string) ($config['base_url'] ?? '');
    }

    private function firstLine(string $message): string
    {
        return trim(strtok($message, "\n") ?: $message);
    }

    private function pass(string $group, string $check, string $message): CheckResult
    {
        return new CheckResult($group, $check, Status::Pass, $message);
    }

    private function warn(string $group, string $check, string $message, ?string $hint = null): CheckResult
    {
        return new CheckResult($group, $check, Status::Warn, $message, $hint);
    }

    private function fail(string $group, string $check, string $message, ?string $hint = null): CheckResult
    {
        return new CheckResult($group, $check, Status::Fail, $message, $hint);
    }
}
