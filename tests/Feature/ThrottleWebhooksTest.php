<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Paysasa\Payments\Middleware\ThrottleWebhooks;

function throttledStatus(string $ip): int
{
    $request = Request::create('/paysasa/webhooks/mpesa', 'POST', server: ['REMOTE_ADDR' => $ip]);

    return app(ThrottleWebhooks::class)->handle($request, fn () => response('ok'))->getStatusCode();
}

beforeEach(function () {
    config()->set('paysasa.rate_limiting.webhooks', ['enabled' => true, 'max_attempts' => 2, 'decay_seconds' => 60]);
});

it('throttles an unknown IP once it exceeds the limit', function () {
    expect(throttledStatus('203.0.113.7'))->toBe(200)
        ->and(throttledStatus('203.0.113.7'))->toBe(200)
        ->and(throttledStatus('203.0.113.7'))->toBe(429);
});

it('never throttles IPs on the M-Pesa webhook allowlist', function () {
    config()->set('paysasa.drivers.mpesa.webhook_ip_allowlist', ['196.201.214.200']);

    foreach (range(1, 6) as $_) {
        expect(throttledStatus('196.201.214.200'))->toBe(200);
    }
});

it('never throttles explicitly trusted IPs', function () {
    config()->set('paysasa.rate_limiting.webhooks.trusted_ips', ['198.51.100.9']);

    foreach (range(1, 6) as $_) {
        expect(throttledStatus('198.51.100.9'))->toBe(200);
    }
});

it('still throttles other IPs while trusted ones are exempt', function () {
    config()->set('paysasa.rate_limiting.webhooks.trusted_ips', ['198.51.100.9']);

    throttledStatus('203.0.113.8');
    throttledStatus('203.0.113.8');

    expect(throttledStatus('203.0.113.8'))->toBe(429);
});
