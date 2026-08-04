# Testing Strategy

Paysasa ships its own test suite (Pest, 31 tests / 80+ assertions covering every driver's happy/failure paths, webhook signature verification, idempotency, the full charge lifecycle against a real SQLite database, and `Payment::fake()` itself) — `composer test` runs it. This document is about testing code that **uses** Paysasa.

## `Payment::fake()`

```php
use Paysasa\Payments\Facades\Payment;

it('marks an invoice paid when the charge succeeds', function () {
    Payment::fake();

    (new InvoiceService())->collect($invoice); // your code, calling Payment::driver(...)->charge() internally

    Payment::assertCharged(fn ($request) => $request->reference === $invoice->number);
    expect($invoice->fresh()->status)->toBe('paid');
});
```

`Payment::fake()` swaps the container binding for a `Testing\PaymentFake` that:

- Never makes an HTTP call.
- Never touches the database (no `Payment`/`Transaction` rows are created — if your own code reads those tables, seed them yourself or assert against the fake's recorded requests instead).
- Never dispatches queue jobs or fires events.
- By default returns a synthetic `PaymentResponse::makeSuccessful(...)` for every `->charge()` — override with `queueResponse()`/`shouldReturn()`.

### Assertion helpers

| Method | Checks |
|---|---|
| `Payment::assertCharged(?Closure $callback = null)` | At least one charge matching the callback (or any charge, if omitted) was made. |
| `Payment::assertChargedOn(string $driver)` | At least one charge was made on that specific driver. |
| `Payment::assertChargedTimes(int $times, ?string $driver = null)` | Exact charge count, optionally scoped to a driver. |
| `Payment::assertNothingCharged()` | No charge was made at all. |
| `Payment::assertRefunded(?Closure $callback = null)` | Same shape, for refunds. |

### Simulating failure

```php
$fake = Payment::fake();
$fake->queueResponse(PaymentResponse::makeFailed(PaymentProvider::Mpesa, message: 'Insufficient funds'));

$response = Payment::driver('mpesa')->amount(100)->phone('254700000000')->charge();

expect($response->failed())->toBeTrue();
```

`queueResponse()` responses are consumed in FIFO order across successive `charge()` calls — useful for testing retry logic. `shouldReturn()` sets a standing default for every call instead of a one-shot queue entry.

## Unit-testing a specific driver

```php
use Illuminate\Support\Facades\Http;
use Paysasa\Payments\Managers\PaymentManager;

it('initiates an STK push', function () {
    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'fake']),
        'sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response(['CheckoutRequestID' => 'ws_CO_1', 'ResponseCode' => '0']),
    ]);

    $driver = app(PaymentManager::class)->driverInstance('mpesa');
    $response = $driver->charge(/* ChargeRequest */);

    expect($response->pending())->toBeTrue();
});
```

This is the pattern used throughout `tests/Unit/Drivers/` — `Http::fake()` the provider's actual endpoints (sandbox URLs match `config('paysasa.drivers.*.base_urls.sandbox')`) and assert on the translated `PaymentResponse`, not on raw HTTP internals.

## Feature-testing the full lifecycle

`tests/Feature/ChargeLifecycleTest.php` exercises the real `InitiatePayment` action against an in-memory SQLite database (via `orchestra/testbench`) — no `Payment::fake()` — to assert that `Payment`, `PaymentAttempt`, and `Transaction` rows are actually persisted correctly and that terminal events fire (or don't) at the right point. Use this pattern when you specifically want to test persistence/event behaviour rather than your own business logic layered on top.

## Webhook testing

```php
it('accepts a validly-signed Stripe webhook', function () {
    $secret = config('paysasa.drivers.stripe.webhook_secret');
    $body = json_encode([...]);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    $this->call('POST', '/paysasa/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_Stripe-Signature' => "t={$timestamp},v1={$signature}",
    ], $body)->assertStatus(200);
});
```

See `tests/Feature/WebhookControllerTest.php` and `tests/Feature/WebhookSignatureTest.php` for the full pattern, including asserting a `401` on a bad signature and `Bus::fake()` + `Bus::assertDispatched(DispatchWebhookJob::class)` for confirming async processing was queued.

## Queue testing

Standard Laravel: `Queue::fake()` / `Bus::fake()` around code that dispatches `Jobs\ProcessPaymentJob`, `Jobs\VerifyPaymentStatusJob`, or `Jobs\DispatchWebhookJob`, then `Bus::assertDispatched(...)`. Nothing Paysasa-specific beyond the job classes themselves being public API.

## Event testing

Standard Laravel `Event::fake([...])` / `Event::assertDispatched(PaymentSuccessful::class, fn ($event) => ...)` — every event in `Events/` is a normal Laravel event class.

## Integration testing against real sandboxes

Not part of the package's own CI (no vendor sandbox credentials are safe to bake into a public repo), but recommended for your application's own test suite before going live: a small, manually-run test hitting each provider's actual sandbox (Daraja sandbox, Stripe test mode, etc.) with real network calls, gated behind an env flag so it never runs in normal CI.
