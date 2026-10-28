# Paysasa

**A unified, driver-based payment gateway abstraction for Laravel 11, 12, and 13, purpose-built for the Kenyan market.**

Paysasa gives a Laravel application one consistent, hardened API — `Payment::driver('mpesa')->amount(2500)->phone('0712345678')->charge()` — for M-Pesa, Airtel Money, Stripe, Pesapal, Flutterwave, Paystack, and PesaLink. Business logic never talks to a provider SDK directly; it talks to Paysasa, and Paysasa orchestrates validation, idempotency, persistence, state transitions, events, and background reconciliation.

This README is the quickstart and overview. The comprehensive architecture specification lives in [`Documentation/`](Documentation/00-overview.md).

---

## Key Highlights

- **Unified Fluent API:** Consistent methods across all mobile money, card, and banking providers.
- **Strict State Machine:** Centralized `TransitionPayment` engine with pessimistic row-locking (`lockForUpdate()`) preventing race conditions (e.g. a late failed callback cannot overwrite a successful payment).
- **Money Safety (No Silent Truncation):** Immutable `Money` value object uses integer minor units. Fractional units for whole-unit rails (like Safaricom M-Pesa) are strictly rejected with `InvalidAmountException` rather than silently truncated.
- **Strict Phone Normalization:** `KenyanMsisdn` validates and standardizes `07xx`, `01xx`, and `+254` phone numbers into E.164 formats.
- **Guaranteed Terminal Lifecycle Events:** Both synchronously-settled charges and webhook-settled transactions (such as M-Pesa STK push) reliably dispatch `PaymentSuccessful`, `PaymentFailed`, `PaymentCancelled`, and `PaymentExpired`.
- **Self-Healing Reconciliation:** Background sweep (`paysasa:reconcile`) combined with single-shot `VerifyPaymentStatusJob` ensures lost or delayed webhooks never leave payments pending indefinitely.
- **Fail-Closed Webhook Security:** Automatic 64KB body size limit, header redaction, SHA-256 raw-body deduplication, and fail-closed M-Pesa verification in production.
- **`Payment::fake()` Testing:** Comprehensive test assertions that fake network and persistence layers without touching external APIs.

---

## Requirements

- PHP 8.2+
- Laravel 11.x, 12.x, or 13.x
- Database: MySQL 8.0+, PostgreSQL 13+, or SQLite 3.35+
- A queue worker for asynchronous webhook and verification processing (`php artisan queue:work`)

---

## Installation

```bash
composer require cyrus-48/paysasa
php artisan paysasa:install --migrate
```

This publishes `config/paysasa.php`, publishes and runs database migrations, and prompts for provider credentials. See [`Documentation/01-installation.md`](Documentation/01-installation.md) for detailed instructions.

---

## Quick Start

### Initiating a Payment (e.g. M-Pesa STK Push)

```php
use Paysasa\Payments\Facades\Payment;

// Initiate an M-Pesa STK push
$response = Payment::driver('mpesa')
    ->amount(2500)
    ->currency('KES')
    ->phone('0712345678')
    ->customer($user)
    ->reference('INV-1001')
    ->description('School Fees')
    ->metadata(['student_id' => 10])
    ->charge();

if ($response->successful()) {
    // Synchronous providers (e.g. pre-authorized cards) settle immediately
    $receipt = $response->receiptNumber();
} elseif ($response->pending()) {
    // Asynchronous providers (STK push, hosted checkouts) settle via webhook or reconciler
    $checkoutId = $response->providerReference();
}
```

### Switching Providers

Swapping gateways requires only changing the driver name:

```php
// Stripe Card (Tokenized)
Payment::driver('stripe')
    ->amount(25)
    ->currency('USD')
    ->paymentMethod($paymentMethodId)
    ->reference('INV-1002')
    ->charge();

// Paystack
Payment::driver('paystack')
    ->amount(1000)
    ->phone('0712345678')
    ->reference('INV-1003')
    ->charge();

// PesaLink Bank Transfer (Experimental)
Payment::driver('pesalink')
    ->amount(50000)
    ->reference('PAYOUT-99')
    ->metadata(['account_number' => '0123456789', 'bank_code' => '01'])
    ->payout();
```

### Digital Wallets (Google Pay & Apple Pay)

Neither Google Pay nor Apple Pay is an independent settlement network. They are client-side tokenization layers over card networks. Frontend SDKs (such as Stripe Elements or the Payment Request Button) exchange device credentials for a payment method token (`pm_*`), which you process via `stripe`:

```php
Payment::driver('stripe')
    ->amount(2500)
    ->currency('KES')
    ->paymentMethod($stripePaymentMethodId)
    ->reference('INV-1004')
    ->charge();
```

---

## Listening to Lifecycle Events

Because asynchronous payments settle via callbacks or reconciliation jobs, business logic should listen for terminal events:

```php
use Illuminate\Support\Facades\Event;
use Paysasa\Payments\Events\PaymentSuccessful;
use Paysasa\Payments\Events\PaymentFailed;
use Paysasa\Payments\Events\PaymentExpired;

// In your EventServiceProvider or listener:
Event::listen(PaymentSuccessful::class, function (PaymentSuccessful $event) {
    $payment = $event->payment;
    // Dispatch order, send SMS receipt, update subscription...
});

Event::listen(PaymentFailed::class, function (PaymentFailed $event) {
    // Alert customer, log failure reason...
});
```

### Anomaly Events

Paysasa also emits events when unexpected conditions occur:
- `PaymentStateConflict`: Triggered when an illegal transition is rejected (e.g. a late Failed callback attempting to overwrite an already Successful payment).
- `PaymentAmountMismatch`: Triggered when a callback's amount differs from the expected amount, keeping the payment in `processing` for manual review.
- `WebhookUnmatched`: Triggered when an incoming webhook cannot be matched to any payment record after multiple retry attempts.

---

## Supported Providers & Maturity

| Driver | Category | Status | Capabilities |
|---|---|---|---|
| `mpesa` | Mobile Money | **Stable** | STK Push, C2B, B2C, reversals, balance inquiries |
| `stripe` | Cards | **Stable** | PaymentIntents, direct charges, refunds, captures |
| `airtel` | Mobile Money | **Beta** | USSD push, collections, refunds |
| `pesapal` | Cards | **Beta** | Hosted checkout, IPN callbacks |
| `flutterwave` | Cards | **Beta** | Standard charges, webhook verification |
| `paystack` | Cards | **Stable** | Hosted checkout, signed + re-verified webhooks, server-side verification, refunds, payouts |
| `pesalink` | Banking | **Experimental** | Account transfers, banking rails (bring your own bank) |

---

## Artisan Commands

Paysasa provides several Artisan commands for operation, reconciliation, and troubleshooting:

```bash
# Initial package setup, config, and migration publishing
php artisan paysasa:install --migrate

# Run reconciliation sweep (sweeps pending/processing payments past threshold)
php artisan paysasa:reconcile

# View unmatched webhook callbacks needing investigation
php artisan paysasa:webhooks:unmatched

# Inspect the status and history of a specific payment reference
php artisan paysasa:status INV-1001

# Register M-Pesa C2B validation and confirmation URLs with Safaricom
php artisan paysasa:mpesa:register-urls
```

> **Automatic Scheduling:** `paysasa:reconcile` automatically registers in Laravel's scheduler to run every minute when `paysasa.reconcile.schedule_enabled` is `true`.

---

## Testing Your Integration

Use `Payment::fake()` in your unit and feature tests to simulate payment flows without hitting third-party APIs:

```php
use Paysasa\Payments\Facades\Payment;

Payment::fake();

// Run your application code...
$user->checkout();

// Assertions:
Payment::assertCharged(fn ($request) => $request->amountMajor() === 2500.0);
Payment::assertChargedOn('mpesa');
Payment::assertChargedTimes(1);
```

You can also queue specific failure or pending responses:

```php
Payment::fake([
    PaymentResponse::makeFailed(PaymentProvider::Mpesa, message: 'DS timeout user cannot be reached'),
]);
```

---

## Documentation Index

| # | Document | Covers |
|---|---|---|
| — | [00-overview.md](Documentation/00-overview.md) | High-level system architecture, principles, package structure |
| 1 | [01-installation.md](Documentation/01-installation.md) | Installation guide, prerequisites, and service registration |
| 2 | [02-configuration.md](Documentation/02-configuration.md) | Comprehensive configuration reference, driver options, env variables |
| 3 | [03-quickstart.md](Documentation/03-quickstart.md) | Step-by-step integration guide for STK push, cards, refunds, and payouts |
| 4 | [04-architecture.md](Documentation/04-architecture.md) | State machine, contracts, transition flow, class diagrams, lifecycles |
| 5 | [05-database-schema.md](Documentation/05-database-schema.md) | Entity relationship diagrams (ERD) and column-level schema definitions |
| 6 | [06-driver-development-guide.md](Documentation/06-driver-development-guide.md) | Guide to authoring custom drivers and banking implementations |
| 7 | [07-webhook-guide.md](Documentation/07-webhook-guide.md) | Webhook intake, signature verification, deduping, and retry handling |
| 8 | [08-security.md](Documentation/08-security.md) | Security model: HMAC signing, IP allowlisting, PCI DSS scope, secret vault |
| 9 | [09-testing.md](Documentation/09-testing.md) | Testing best practices using `Payment::fake()`, Pest, and HTTP fakes |
| 10 | [10-performance-and-scalability.md](Documentation/10-performance-and-scalability.md) | High-throughput queue configuration, connection pooling, and caching |
| 11 | [11-troubleshooting.md](Documentation/11-troubleshooting.md) | Common errors, Daraja gotchas, and resolution procedures |
| 12 | [12-upgrade-guide.md](Documentation/12-upgrade-guide.md) | Semantic versioning policy and version-to-version migration notes |
| 13 | [13-contributing.md](Documentation/13-contributing.md) | Contribution guidelines, coding standards, and PR workflows |
| 14 | [14-roadmap.md](Documentation/14-roadmap.md) | Implemented scope and future feature considerations |

---
## NOTE :  BETA 
## License

MIT License. See [LICENSE](LICENSE) for details.
