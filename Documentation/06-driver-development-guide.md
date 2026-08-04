# Driver Development Guide

Third-party drivers never require a core edit — that's the Open/Closed Principle applied concretely. There are two ways to add one.

## Option A: Register via `extend()` (fastest, for app-local drivers)

```php
// AppServiceProvider::boot()
use Paysasa\Payments\Facades\Payment;

Payment::extend('equitel', function ($app, array $config) {
    return new \App\Payments\Drivers\EquitelDriver($config, $app->make(\Paysasa\Payments\Support\PaymentLogger::class));
});
```

```php
// config/paysasa.php — add credentials just like any built-in provider
'drivers' => [
    'equitel' => [
        'api_key' => env('EQUITEL_API_KEY'),
    ],
],
```

```php
Payment::driver('equitel')->amount(500)->phone('254700000000')->charge();
```

`extend()` bypasses `CredentialVault` resolution and is handed the merchant-resolved config array directly, so multi-merchant support works for custom drivers too.

## Option B: A proper package (for a driver you intend to publish/reuse)

1. `composer require your-vendor/paysasa-equitel`
2. Your package's own service provider does the same `Payment::extend(...)` call in its `boot()` method.
3. Ship your own `Contracts\PaymentDriver` implementation, extending `Paysasa\Payments\Drivers\AbstractDriver` for the shared HTTP client/logging/config plumbing — but you don't have to; `AbstractDriver` is a convenience, not a requirement. Anything implementing `Contracts\PaymentDriver` (+ whichever capability interfaces apply) works.

## Minimum viable driver

```php
namespace App\Payments\Drivers;

use Paysasa\Payments\Drivers\AbstractDriver;
use Paysasa\Payments\Contracts\PaymentDriver;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Enums\PaymentProvider;

class EquitelDriver extends AbstractDriver implements PaymentDriver
{
    public function provider(): PaymentProvider
    {
        // If Equitel isn't in the built-in PaymentProvider enum, either open
        // a PR to add it, or — for a fully third-party provider outside the
        // package's known set — return the closest matching category driver
        // pattern and track your own provider identity in `metadata`.
        return PaymentProvider::Mpesa; // placeholder — see note above
    }

    public function charge(ChargeRequest $request): PaymentResponse
    {
        $this->requireConfig(['api_key']);

        $response = $this->http()->withToken($this->config['api_key'])->post('/charge', [
            'amount' => $request->amount,
            'phone' => $request->phone,
            'reference' => $request->reference,
        ]);

        $this->throwIfFailed($response, 'charge');
        $result = $response->json();

        return PaymentResponse::makePending($this->provider(), null, $result['id']);
    }

    public function verify(string $providerReference): PaymentResponse
    {
        // ...
    }
}
```

## Adding capabilities

Implement only the interfaces your provider actually supports:

| You need... | Implement |
|---|---|
| Refunds | `Contracts\Refundable` |
| B2C/B2B payouts | `Contracts\PayoutCapable` |
| Two-phase (authorize/capture) cards | `Contracts\Authorizable` |
| Mobile-money-style reversal | `Contracts\Reversible` |
| Account balance queries | `Contracts\BalanceInquirable` |
| Inbound webhooks | `Contracts\WebhookHandler` |

`FluentPaymentBuilder` type-checks `instanceof` before calling any of these and throws `Exceptions\UnsupportedOperationException` with a clear message if you call e.g. `->refund()` on a driver that doesn't implement `Refundable` — you don't need to guard against this yourself.

## Adding webhook support for your driver

1. Implement `handleCallback(WebhookPayload $payload): PaymentResponse` — translate the provider's callback shape into a `PaymentResponse`.
2. Add a case to `Webhooks\WebhookDispatcher::verifierFor()` (or, if you're a separate package, decorate/extend `WebhookDispatcher` via the container) pointing at either an existing `Verifiers\*` strategy (e.g. `HmacHeaderSignatureVerifier` if your provider does header-based HMAC) or your own `Contracts\SignatureVerifier` implementation.
3. Add your provider's enum case (see note above on `PaymentProvider` — extending a backed enum requires either a PR to the package or, for local-only providers, forking the enum is the one place this architecture asks for a core change; this is a deliberate, documented trade-off of using a PHP `enum` over a string constant, made for the type-safety benefit everywhere else).

## Banking rails specifically

Extend `Drivers\Banking\AbstractBankingDriver` (see `PesaLinkDriver`/`EftDriver`/`RtgsDriver` as templates) and override `endpointPrefix()` plus any payload-shape method (`resolveAccount()`, `transfer()`, `responseFromTransfer()`) that your bank's actual API deviates from the generic shape on — see `04-architecture.md` for why there's no single "correct" bank API shape to standardize on further than this.

## Testing your driver

Write the same shape of test as `tests/Unit/Drivers/MpesaDriverTest.php`: `Http::fake()` the provider's endpoints, resolve your driver via `app(PaymentManager::class)->driverInstance('your-driver')`, and assert on the returned `PaymentResponse`. See [`09-testing.md`](09-testing.md).
