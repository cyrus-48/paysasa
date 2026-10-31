<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Enums\WebhookStatus;
use Paysasa\Payments\Models\Payment;
use Paysasa\Payments\Models\Webhook;
use Paysasa\Payments\Support\Doctor\CheckResult;
use Paysasa\Payments\Support\Doctor\Doctor;
use Paysasa\Payments\Support\Doctor\Ping;
use Paysasa\Payments\Support\Doctor\Status;

beforeEach(function () {
    // Nothing in these tests may touch the real network.
    Http::preventStrayRequests();

    config()->set('paysasa.callback_base_url', 'https://shop.example.com');
    config()->set('paysasa.drivers.mpesa.stk_callback_url', 'https://shop.example.com/paysasa/webhooks/mpesa');
});

/**
 * @param list<string> $only
 *
 * @return list<CheckResult>
 */
function doctorRun(array $only = [], bool $network = false, bool $probe = false): array
{
    return app(Doctor::class)->run($only, $network, $probe);
}

/** @param list<CheckResult> $results */
function doctorCheck(array $results, string $group, string $check): ?CheckResult
{
    foreach ($results as $result) {
        if ($result->group === $group && $result->check === $check) {
            return $result;
        }
    }

    return null;
}

/**
 * Fakes the Daraja token endpoint (a probe run also performs the live credential check)
 * plus whatever the callback host should answer.
 */
function doctorFakeHosts(mixed $shop): void
{
    Http::fake([
        'sandbox.safaricom.co.ke/*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
        'shop.example.com/*' => $shop,
    ]);
}

function doctorProduction(): void
{
    app()->detectEnvironment(fn () => 'production');
}

// ---------------------------------------------------------------- environment

it('passes the environment checks on a healthy install', function () {
    $results = doctorRun();

    expect(doctorCheck($results, 'environment', 'database')->status)->toBe(Status::Pass)
        ->and(doctorCheck($results, 'environment', 'webhook routes')->status)->toBe(Status::Pass)
        ->and(doctorCheck($results, 'environment', 'callback base URL')->status)->toBe(Status::Pass)
        ->and(doctorCheck($results, 'environment', 'scheduler')->status)->toBe(Status::Pass)
        ->and(doctorCheck($results, 'environment', 'stuck payments')->status)->toBe(Status::Pass);
});

it('fails when the cache store cannot hold idempotency keys in production', function () {
    doctorProduction();
    config()->set('cache.default', 'array');

    expect(doctorCheck(doctorRun(), 'environment', 'cache')->status)->toBe(Status::Fail);
});

it('flags the sync and null queue connections', function (string $connection, bool $production, Status $expected) {
    if ($production) {
        doctorProduction();
    }

    config()->set('paysasa.queue.connection', $connection);

    expect(doctorCheck(doctorRun(), 'environment', 'queue')->status)->toBe($expected);
})->with([
    'sync locally is fine' => ['sync', false, Status::Pass],
    'sync in production warns' => ['sync', true, Status::Warn],
    'null always fails' => ['null', false, Status::Fail],
    'redis is fine' => ['redis', true, Status::Pass],
]);

it('rejects a non-https or local callback base URL in production', function (string $url, Status $expected) {
    doctorProduction();
    config()->set('paysasa.callback_base_url', $url);

    expect(doctorCheck(doctorRun(), 'environment', 'callback base URL')->status)->toBe($expected);
})->with([
    'https public' => ['https://shop.example.com', Status::Pass],
    'plain http' => ['http://shop.example.com', Status::Fail],
    'localhost' => ['https://localhost', Status::Fail],
    'private ip' => ['https://10.0.0.5', Status::Fail],
    '.test domain' => ['https://shop.test', Status::Fail],
]);

it('warns when payments are stuck in pending, the symptom of a dead worker', function () {
    $payment = Payment::create([
        'provider' => PaymentProvider::Mpesa,
        'category' => PaymentProvider::Mpesa->category(),
        'reference' => 'STUCK_1',
        'provider_reference' => 'PR_STUCK_1',
        'status' => PaymentStatus::Pending,
        'currency' => 'KES',
        'amount_minor' => 1000,
    ]);
    Payment::query()->whereKey($payment->id)->update(['created_at' => now()->subHours(5)]);

    $check = doctorCheck(doctorRun(), 'environment', 'stuck payments');

    expect($check->status)->toBe(Status::Warn)
        ->and($check->message)->toContain('1 payment');
});

// ---------------------------------------------------------------- driver selection and credentials

it('only checks drivers that have credentials, skipping the rest', function () {
    $results = doctorRun();

    expect(doctorCheck($results, 'mpesa', 'credentials')->status)->toBe(Status::Pass)
        ->and(doctorCheck($results, 'stripe', 'credentials')->status)->toBe(Status::Pass)
        ->and(doctorCheck($results, 'airtel', 'driver')->status)->toBe(Status::Skip)
        ->and(doctorCheck($results, 'flutterwave', 'driver')->status)->toBe(Status::Skip);
});

it('reports the missing settings for a driver that is half configured', function () {
    config()->set('paysasa.drivers.mpesa.consumer_key', null);
    config()->set('paysasa.drivers.mpesa.passkey', null);

    $check = doctorCheck(doctorRun(), 'mpesa', 'credentials');

    expect($check->status)->toBe(Status::Fail)
        ->and($check->message)->toContain('consumer_key')->toContain('passkey');
});

it('warns when the default driver has no credentials at all', function () {
    config()->set('paysasa.default', 'airtel');

    expect(doctorCheck(doctorRun(), 'airtel', 'driver')->status)->toBe(Status::Warn);
});

it('checks a named driver even when it has no credentials, and rejects unknown names', function () {
    $results = doctorRun(['flutterwave', 'nope']);

    expect(doctorCheck($results, 'flutterwave', 'credentials')->status)->toBe(Status::Fail)
        ->and(doctorCheck($results, 'nope', 'driver')->status)->toBe(Status::Fail)
        ->and(doctorCheck($results, 'mpesa', 'credentials'))->toBeNull();
});

// ---------------------------------------------------------------- mode and value sanity

it('flags a sandbox-mode driver in production and a live-mode driver elsewhere', function () {
    config()->set('paysasa.drivers.mpesa.env', 'sandbox');
    doctorProduction();
    expect(doctorCheck(doctorRun(), 'mpesa', 'mode')->status)->toBe(Status::Warn);

    config()->set('paysasa.drivers.mpesa.env', 'production');
    app()->detectEnvironment(fn () => 'local');
    expect(doctorCheck(doctorRun(), 'mpesa', 'mode')->status)->toBe(Status::Warn);
});

it('fails a Stripe test key in production and warns about a live key elsewhere', function () {
    doctorProduction();
    config()->set('paysasa.drivers.stripe.secret_key', 'sk_test_abc');
    expect(doctorCheck(doctorRun(), 'stripe', 'secret key')->status)->toBe(Status::Fail);

    app()->detectEnvironment(fn () => 'local');
    config()->set('paysasa.drivers.stripe.secret_key', 'sk_live_abc');
    expect(doctorCheck(doctorRun(), 'stripe', 'secret key')->status)->toBe(Status::Warn);
});

it('fails the public Daraja sandbox shortcode and passkey in production', function () {
    doctorProduction();
    config()->set('paysasa.drivers.mpesa.env', 'production');
    config()->set('paysasa.drivers.mpesa.shortcode', '174379');
    config()->set('paysasa.drivers.mpesa.passkey', 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919');

    $results = doctorRun();

    expect(doctorCheck($results, 'mpesa', 'shortcode')->status)->toBe(Status::Fail)
        ->and(doctorCheck($results, 'mpesa', 'passkey')->status)->toBe(Status::Fail);
});

// ---------------------------------------------------------------- callback URLs

it('checks callback URLs for scheme, host and route', function (string $url, bool $production, Status $expected) {
    if ($production) {
        doctorProduction();
    }

    config()->set('paysasa.drivers.mpesa.stk_callback_url', $url);

    expect(doctorCheck(doctorRun(), 'mpesa', 'URL (stk_callback_url)')->status)->toBe($expected);
})->with([
    'correct' => ['https://shop.example.com/paysasa/webhooks/mpesa', true, Status::Pass],
    'http in production' => ['http://shop.example.com/paysasa/webhooks/mpesa', true, Status::Fail],
    'http locally' => ['http://shop.example.com/paysasa/webhooks/mpesa', false, Status::Warn],
    'localhost in production' => ['https://localhost/paysasa/webhooks/mpesa', true, Status::Fail],
    'localhost locally' => ['https://localhost/paysasa/webhooks/mpesa', false, Status::Warn],
    'not a url' => ['shop.example.com/callback', false, Status::Fail],
    'wrong path' => ['https://shop.example.com/api/mpesa/callback', false, Status::Warn],
]);

it('rejects a callback host that the driver would refuse', function () {
    config()->set('paysasa.drivers.mpesa.allowed_callback_hosts', ['other.example.com']);

    expect(doctorCheck(doctorRun(), 'mpesa', 'URL (stk_callback_url)')->status)->toBe(Status::Fail);
});

it('prefers the opaque alias URL and fails the plain one in production without an allowlist', function () {
    config()->set('paysasa.webhook_aliases', ['abc123def456' => 'mpesa']);

    config()->set('paysasa.drivers.mpesa.stk_callback_url', 'https://shop.example.com/paysasa/webhooks/abc123def456');
    expect(doctorCheck(doctorRun(), 'mpesa', 'URL (stk_callback_url)')->status)->toBe(Status::Pass);

    config()->set('paysasa.drivers.mpesa.stk_callback_url', 'https://shop.example.com/paysasa/webhooks/mpesa');
    expect(doctorCheck(doctorRun(), 'mpesa', 'URL (stk_callback_url)')->status)->toBe(Status::Warn);

    doctorProduction();
    expect(doctorCheck(doctorRun(), 'mpesa', 'URL (stk_callback_url)')->status)->toBe(Status::Fail);
});

it('tells you which URL to register in the dashboard of providers that have no URL setting', function () {
    $check = doctorCheck(doctorRun(['stripe']), 'stripe', 'URL (webhook URL to register)');

    expect($check->status)->toBe(Status::Pass)
        ->and($check->message)->toContain('https://shop.example.com/paysasa/webhooks/stripe');
});

// ---------------------------------------------------------------- webhook verification

it('fails when a signed provider has no webhook secret, because every webhook would 401', function () {
    config()->set('paysasa.drivers.stripe.webhook_secret', '');

    expect(doctorCheck(doctorRun(), 'stripe', 'webhook verification')->status)->toBe(Status::Fail);
});

it('passes when the webhook secret is set', function () {
    expect(doctorCheck(doctorRun(), 'stripe', 'webhook verification')->status)->toBe(Status::Pass);
});

it('fails unsigned M-Pesa callbacks in production without an allowlist or alias, and passes with either', function () {
    doctorProduction();
    config()->set('paysasa.drivers.mpesa.env', 'production');

    expect(doctorCheck(doctorRun(), 'mpesa', 'webhook verification')->status)->toBe(Status::Fail);

    // A secret route alias is enough.
    config()->set('paysasa.webhook_aliases', ['abc123def456' => 'mpesa']);
    expect(doctorCheck(doctorRun(), 'mpesa', 'webhook verification')->status)->toBe(Status::Pass);
});

// ---------------------------------------------------------------- live credential check

it('reports a provider that accepts the credentials', function () {
    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
        'api.stripe.com/v1/balance' => Http::response(['object' => 'balance', 'livemode' => false]),
    ]);

    $results = doctorRun([], network: true);

    expect(doctorCheck($results, 'mpesa', 'credential check')->status)->toBe(Status::Pass)
        ->and(doctorCheck($results, 'stripe', 'credential check')->status)->toBe(Status::Pass)
        ->and(doctorCheck($results, 'stripe', 'credential check')->message)->toContain('test mode');
});

it('fails with an environment hint when the provider rejects the credentials', function () {
    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['errorMessage' => 'Invalid Authentication passed'], 400),
        'api.stripe.com/v1/balance' => Http::response(['error' => ['message' => 'Invalid API Key provided']], 401),
    ]);

    $results = doctorRun([], network: true);

    $mpesa = doctorCheck($results, 'mpesa', 'credential check');

    expect($mpesa->status)->toBe(Status::Fail)
        ->and($mpesa->message)->toContain('rejected')
        ->and($mpesa->hint)->toContain('sandbox')
        ->and(doctorCheck($results, 'stripe', 'credential check')->status)->toBe(Status::Fail);
});

it('does not treat a provider outage as bad credentials', function () {
    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response('Bad gateway', 502),
        'api.stripe.com/v1/balance' => Http::response(['livemode' => false]),
    ]);

    $check = doctorCheck(doctorRun(['mpesa'], network: true), 'mpesa', 'credential check');

    expect($check->status)->toBe(Status::Fail)
        ->and($check->message)->toContain('HTTP 502')
        ->and($check->message)->not->toContain('rejected the credentials');
});

it('reports a connection failure as such', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host: sandbox.safaricom.co.ke')]);

    $check = doctorCheck(doctorRun(['mpesa'], network: true), 'mpesa', 'credential check');

    expect($check->status)->toBe(Status::Fail)
        ->and($check->message)->toContain('Could not reach');
});

it('fails a Stripe key that works but is in test mode while the app is in production', function () {
    doctorProduction();
    config()->set('paysasa.drivers.stripe.secret_key', 'sk_live_abc');
    Http::fake(['api.stripe.com/v1/balance' => Http::response(['livemode' => false])]);

    expect(doctorCheck(doctorRun(['stripe'], network: true), 'stripe', 'credential check')->status)->toBe(Status::Fail);
});

it('does not call any provider with --offline semantics, and skips the live checks', function () {
    Http::fake();

    $results = doctorRun();

    Http::assertNothingSent();
    expect(doctorCheck($results, 'mpesa', 'credential check')->status)->toBe(Status::Skip);
});

it('skips the live check when settings are missing instead of sending half a request', function () {
    config()->set('paysasa.drivers.mpesa.consumer_secret', null);
    Http::fake();

    $check = doctorCheck(doctorRun(['mpesa'], network: true), 'mpesa', 'credential check');

    Http::assertNothingSent();
    expect($check->status)->toBe(Status::Skip);
});

// ---------------------------------------------------------------- reachability probe

it('pings the callback URL with a token only this application can produce', function () {
    doctorFakeHosts(Http::response(['paysasa_doctor' => true, 'route' => 'mpesa']));

    $check = doctorCheck(doctorRun(['mpesa'], network: true, probe: true), 'mpesa', 'reachability (stk_callback_url)');

    expect($check->status)->toBe(Status::Pass);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://shop.example.com/paysasa/webhooks/mpesa'
        && $request->header(Ping::HEADER) === [Ping::token('mpesa')]);
});

it('classifies what the URL answered with', function (int $status, mixed $body, Status $expected) {
    doctorFakeHosts(Http::response($body, $status));

    $check = doctorCheck(doctorRun(['mpesa'], network: true, probe: true), 'mpesa', 'reachability (stk_callback_url)');

    expect($check->status)->toBe($expected);
})->with([
    'route missing' => [404, ['message' => 'Not Found'], Status::Fail],
    'server error' => [500, 'oops', Status::Fail],
    'maintenance mode' => [503, 'down', Status::Warn],
    'blocked by a firewall or auth' => [401, ['message' => 'Unauthenticated'], Status::Warn],
    'csrf' => [419, 'expired', Status::Warn],
    'ping token not accepted' => [403, ['message' => 'Invalid doctor token'], Status::Warn],
    'something else answered' => [200, '<html>Welcome</html>', Status::Warn],
]);

it('reports an unreachable host', function () {
    doctorFakeHosts(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

    expect(doctorCheck(doctorRun(['mpesa'], network: true, probe: true), 'mpesa', 'reachability (stk_callback_url)')->status)->toBe(Status::Fail);
});

it('does not probe local addresses or routes that are not Paysasa routes', function () {
    doctorFakeHosts(Http::response('should not be called', 500));

    config()->set('paysasa.drivers.mpesa.stk_callback_url', 'https://localhost/paysasa/webhooks/mpesa');
    expect(doctorCheck(doctorRun(['mpesa'], network: true, probe: true), 'mpesa', 'reachability (stk_callback_url)')->status)->toBe(Status::Skip);

    config()->set('paysasa.drivers.mpesa.stk_callback_url', 'https://shop.example.com/custom/mpesa-callback');
    expect(doctorCheck(doctorRun(['mpesa'], network: true, probe: true), 'mpesa', 'reachability (stk_callback_url)')->status)->toBe(Status::Skip);

    // Only the provider credential check went out; no request reached the callback hosts.
    Http::assertNotSent(fn (Request $request) => ! str_contains($request->url(), 'safaricom'));
});

// ---------------------------------------------------------------- the ping endpoint

it('answers a valid doctor ping without storing or verifying anything', function () {
    $this->getJson('/paysasa/webhooks/mpesa', [Ping::HEADER => Ping::token('mpesa')])
        ->assertOk()
        ->assertJson(['paysasa_doctor' => true, 'route' => 'mpesa']);

    expect(Webhook::count())->toBe(0);
});

it('rejects a ping with the wrong token, also without storing anything', function () {
    $this->getJson('/paysasa/webhooks/mpesa', [Ping::HEADER => 'guess'])->assertStatus(403);
    $this->getJson('/paysasa/webhooks/mpesa', [Ping::HEADER => Ping::token('stripe')])->assertStatus(403);

    expect(Webhook::count())->toBe(0);
});

it('answers a ping on an opaque alias route when the token is for that alias', function () {
    config()->set('paysasa.webhook_aliases', ['abc123def456' => 'mpesa']);

    $this->getJson('/paysasa/webhooks/abc123def456', [Ping::HEADER => Ping::token('abc123def456')])->assertOk();
});

// ---------------------------------------------------------------- recent webhooks

it('warns when every recent webhook failed verification', function () {
    foreach (range(1, 3) as $i) {
        Webhook::create(['provider' => 'stripe', 'status' => WebhookStatus::VerificationFailed, 'payload' => ['n' => $i]]);
    }

    $check = doctorCheck(doctorRun(), 'stripe', 'recent webhooks');

    expect($check->status)->toBe(Status::Warn)
        ->and($check->message)->toContain('All 3');
});

it('stays quiet about providers that have received no webhooks', function () {
    expect(doctorCheck(doctorRun(), 'stripe', 'recent webhooks')->status)->toBe(Status::Skip);
});

it('is happy when webhooks are arriving and verifying', function () {
    Webhook::create(['provider' => 'stripe', 'status' => WebhookStatus::Processed, 'payload' => ['n' => 1]]);

    expect(doctorCheck(doctorRun(), 'stripe', 'recent webhooks')->status)->toBe(Status::Pass);
});

// ---------------------------------------------------------------- the command

it('exits successfully when there are only warnings, and fails on warnings with --strict', function () {
    // The shared test config uses an .example/.test host, which only warns outside production.
    config()->set('paysasa.drivers.mpesa.stk_callback_url', 'http://shop.example.com/paysasa/webhooks/mpesa');

    $this->artisan('paysasa:doctor', ['--offline' => true])->assertExitCode(0);
    $this->artisan('paysasa:doctor', ['--offline' => true, '--strict' => true])->assertExitCode(1);
});

it('exits non-zero when a check fails', function () {
    config()->set('paysasa.drivers.mpesa.consumer_key', null);

    $this->artisan('paysasa:doctor', ['--offline' => true])->assertExitCode(1);
});

it('limits the run to the drivers named on the command line', function () {
    Artisan::call('paysasa:doctor', ['drivers' => ['stripe'], '--offline' => true, '--json' => true]);

    $groups = collect(json_decode(Artisan::output(), true)['results'])->pluck('group')->unique()->all();

    expect($groups)->toContain('environment')->toContain('stripe')->not->toContain('mpesa');
});

it('prints machine-readable JSON with a summary', function () {
    Artisan::call('paysasa:doctor', ['--offline' => true, '--json' => true]);

    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toHaveKeys(['summary', 'results'])
        ->and($decoded['summary'])->toHaveKeys(['pass', 'warn', 'fail', 'skip'])
        ->and($decoded['results'][0])->toHaveKeys(['group', 'check', 'status', 'message', 'hint']);
});

it('prints a readable report', function () {
    $this->artisan('paysasa:doctor', ['--offline' => true])
        ->expectsOutputToContain('environment')
        ->expectsOutputToContain('mpesa')
        ->expectsOutputToContain('passed')
        ->assertExitCode(0);
});
