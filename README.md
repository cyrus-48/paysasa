# Paysasa

**A unified, driver-based payment gateway abstraction for Laravel 11/12, purpose-built for the Kenyan market.**

Paysasa gives a Laravel application one consistent API — `Payment::driver('mpesa')->amount(2500)->phone('0712345678')->charge()` — for M-Pesa, Airtel Money, T-Kash, Stripe, Pesapal, Flutterwave, Paystack, Google Pay, Apple Pay, and Kenyan bank rails (PesaLink, EFT, RTGS, virtual accounts). Business logic never talks to a provider SDK directly; it talks to Paysasa, and Paysasa talks to whichever driver is configured.

This README is the entry point. The full architecture specification — the 20-point technical blueprint this package was built against — lives in [`Documentation/`](Documentation/00-overview.md).

---

## Why

Every Kenyan Laravel project ends up writing the same brittle integration code against Safaricom's Daraja API, then again against Stripe, then again against Pesapal, each with its own auth scheme, its own webhook shape, its own retry semantics — usually scattered across controllers with no shared persistence model, no idempotency protection, and no events for the rest of the app to react to. Paysasa is that integration layer, built once, tested, and made extensible.

## Requirements

- PHP 8.2+
- Laravel 11.x or 12.x
- A queue worker for asynchronous webhook/verification processing (recommended: Redis + `php artisan queue:work`)

## Installation

```bash
composer require paysasa/payments
php artisan paysasa:install --migrate
```

This publishes `config/paysasa.php`, publishes and runs the package migrations, and prints the credentials you need to set per provider. See [`Documentation/01-installation.md`](Documentation/01-installation.md) for the full walkthrough and [`Documentation/02-configuration.md`](Documentation/02-configuration.md) for every config key.

## Quick start

```php
use Paysasa\Payments\Facades\Payment;

// Collect a payment via M-Pesa STK Push
$response = Payment::driver('mpesa')
    ->amount(2500)
    ->currency('KES')
    ->phone('254712345678')
    ->customer($user)
    ->reference('INV1001')
    ->description('School Fees')
    ->metadata([
        'student_id' => 10,
        'invoice' => 455,
    ])
    ->charge();

if ($response->successful()) {
    // synchronous providers (cards) settle immediately
} elseif ($response->pending()) {
    // async providers (STK push, hosted checkout) settle later via webhook —
    // listen for Paysasa\Payments\Events\PaymentSuccessful instead
}
```

Swapping providers is a one-word change:

```php
Payment::driver('stripe')->amount(25)->currency('USD')->cardToken($pm)->reference('INV1002')->charge();
Payment::driver('paystack')->amount(1000)->phone('254712345678')->reference('INV1003')->charge();
Payment::driver('pesalink')->amount(50000)->reference('PAYOUT-99')->metadata(['account_number' => '0123456789', 'bank_code' => '01'])->payout();
```

Every driver returns the exact same [`PaymentResponse`](src/DTOs/PaymentResponse.php) shape — `successful()`, `failed()`, `pending()`, `cancelled()`, `transactionId()`, `providerReference()`, `amount()`, `currency()`, `status()`, `receiptNumber()`, `rawResponse()` — so application code is written once against the abstraction, never against a specific gateway.

## Supported providers

| Category | Drivers | Notes |
|---|---|---|
| Mobile Money | `mpesa`, `airtel`, `tkash` | STK/USSD push, C2B, B2C, reversal, balance |
| Cards | `stripe`, `pesapal`, `flutterwave`, `paystack` | Direct charge (Stripe) or hosted checkout (others) |
| Wallets | `google_pay`, `apple_pay` | Adapters that delegate settlement to a card `gateway` driver — see [Documentation/04-architecture.md](Documentation/04-architecture.md#wallets-are-adapters-not-rails) |
| Banking | `pesalink`, `eft`, `rtgs`, `virtual_account` | Generic bank-rail abstraction — extend `AbstractBankingDriver` per bank you integrate |

## Testing your integration

```php
use Paysasa\Payments\Facades\Payment;

Payment::fake();

// ... exercise your code ...

Payment::assertCharged(fn ($request) => $request->amount === 2500.0);
Payment::assertChargedOn('mpesa');
Payment::assertChargedTimes(1);
```

`Payment::fake()` never touches the database, the queue, or the network — see [`Documentation/09-testing.md`](Documentation/09-testing.md).

## Documentation

| # | Document | Covers |
|---|---|---|
| — | [00-overview.md](Documentation/00-overview.md) | High-level system architecture, principles, package structure |
| 1 | [01-installation.md](Documentation/01-installation.md) | Installation guide |
| 2 | [02-configuration.md](Documentation/02-configuration.md) | Configuration guide, every env var |
| 3 | [03-quickstart.md](Documentation/03-quickstart.md) | Quick start, common flows (STK, cards, refunds, payouts) |
| 4 | [04-architecture.md](Documentation/04-architecture.md) | Contracts, service layer, driver architecture, class diagrams, transaction/webhook/event lifecycles, queue workflow, auth flow, error handling, wallets-as-adapters |
| 5 | [05-database-schema.md](Documentation/05-database-schema.md) | Full ERD and column-by-column explanation |
| 6 | [06-driver-development-guide.md](Documentation/06-driver-development-guide.md) | Writing a third-party driver without touching core |
| 7 | [07-webhook-guide.md](Documentation/07-webhook-guide.md) | Webhook lifecycle, signature verification per provider, replay protection |
| 8 | [08-security.md](Documentation/08-security.md) | Security architecture: HMAC, idempotency, PCI DSS, OWASP, secrets |
| 9 | [09-testing.md](Documentation/09-testing.md) | Testing strategy: `Payment::fake()`, feature/unit/integration tests |
| 10 | [10-performance-and-scalability.md](Documentation/10-performance-and-scalability.md) | Performance optimization and scalability considerations |
| 11 | [11-troubleshooting.md](Documentation/11-troubleshooting.md) | Common errors and fixes |
| 12 | [12-upgrade-guide.md](Documentation/12-upgrade-guide.md) | Semver policy and upgrade notes |
| 13 | [13-contributing.md](Documentation/13-contributing.md) | Contribution guide, coding standards |
| 14 | [14-roadmap.md](Documentation/14-roadmap.md) | Future roadmap |

## License

MIT.
