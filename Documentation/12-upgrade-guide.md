# Upgrade Guide

## Versioning policy

Paysasa follows [Semantic Versioning](https://semver.org/). Given the surface area of a payment package, the following are treated as the **public API** subject to semver guarantees:

- `Contracts/*` interface signatures
- `DTOs/*` and `Enums/*` public shapes (adding a new optional constructor parameter or a new enum case is minor; removing/renaming an existing one is major)
- `Facades\Payment` / `PaymentManager` / `FluentPaymentBuilder`'s public methods
- `Events/*` constructor signatures
- Published config keys (a key being removed or renamed is major; a new key with a sensible default is minor)
- Migration table/column names for existing tables (a new table or nullable column is minor; renaming/dropping an existing column is major, shipped with its own migration + deprecation notice one minor version ahead)

Internal classes (`Services/*` HTTP clients, driver-internal helper methods, anything not listed above) may change within a minor version if the change doesn't alter observable behaviour through the public API.

## Upgrading between minor versions

```bash
composer update cyrus-48/paysasa
php artisan vendor:publish --tag=paysasa-config --force  # review the diff before overwriting your customized config
php artisan vendor:publish --tag=paysasa-migrations
php artisan migrate
```

Always diff a freshly-published `config/paysasa.php` against your own before force-overwriting — new provider config blocks or settings are additive, but a `--force` publish replaces the whole file.

### Upgrading to 0.10

0.10 is a minor release, but pre-1.0 it carries two breaking changes for specific drivers. Check both before you `composer update`:

- **Paystack removed.** See the 1.0 driver-removal note below: archive any `provider = 'paystack'` rows *before* upgrading, or those payments can no longer be loaded.
- **Airtel Money callbacks.** `drivers.airtel.webhook_secret` / `AIRTEL_WEBHOOK_SECRET` and `public_key_id` are removed: Airtel's callbacks carry no signature Paysasa can verify, so the old HMAC check could never pass against real callbacks. In production the Airtel webhook endpoint now **refuses every request** until you set `AIRTEL_WEBHOOK_IP_ALLOWLIST` (comma-separated source IPs) and/or receive callbacks on a secret route alias (`PAYSASA_AIRTEL_WEBHOOK_ALIAS`, then register `/paysasa/webhooks/{alias}` with Airtel). A claimed success is also confirmed with Airtel's transaction enquiry before it settles a payment (`AIRTEL_CONFIRM_CALLBACKS`, default `true`). Payouts additionally need `AIRTEL_DISBURSEMENT_PIN` and `AIRTEL_PUBLIC_KEY`, and refunds are full-only.

After upgrading, run `php artisan paysasa:doctor`: it flags exactly these kinds of gaps (an unsigned provider in production with no allowlist or alias, missing keys, credentials the provider rejects).

## Upgrading between major versions

Each major version ships an `UPGRADE-{version}.md` at the package root (not yet applicable — this is the 1.0 baseline) with:

1. A list of removed/renamed public API surface, with the direct replacement.
2. Any required data migration for renamed/restructured columns, shipped as an idempotent Artisan command (`paysasa:upgrade-vN`) rather than expecting you to write it yourself.
3. Config keys that changed shape, with before/after examples.

## Upgrading to 1.0 (Refactoring & Hardening)

### 1. Driver removals
The following unproven and phantom drivers have been removed:
- `GooglePayDriver` & `ApplePayDriver`: Use `Payment::driver('stripe')->paymentMethod($token)->charge()` instead.
- `TKashDriver`: Telkom Kenya lacks a standardized public API; removed until an official API is released.
- `EftDriver`, `RtgsDriver`, and `VirtualAccountDriver`: Removed in favor of `PesaLinkDriver` (experimental) or custom bank drivers extending `AbstractBankingDriver`.
- `PaystackDriver`: Removed (it never left beta). Stored payments with `provider = 'paystack'` can no longer be loaded through the Eloquent models because `Payment::$provider` is cast to the `PaymentProvider` enum, which no longer has a `paystack` case — this includes `paysasa:reconcile` if such a payment is still pending. **Before upgrading:** let in-flight Paystack payments reach a terminal state, export/archive and delete your `provider = 'paystack'` rows (in `payments` and `webhooks`), remove `PAYSTACK_*` from `.env`, and repoint the Paystack dashboard webhook (`/paysasa/webhooks/paystack` now returns 404). For hosted card checkout use `stripe`, `pesapal` or `flutterwave`.

### 2. Money Value Object & Fraction Validation
Amounts in `ChargeRequest` now use the immutable `Money` value object. Fractional units for whole-unit currencies (e.g. KES) are rejected (`InvalidAmountException`) rather than silently truncated.

### 3. State Machine & Event Dispatching
Status transitions are now strictly managed by `TransitionPayment` with database row-level locking (`lockForUpdate()`). Webhook-settled payments now consistently fire terminal events (`PaymentSuccessful`, `PaymentFailed`, `PaymentCancelled`, `PaymentExpired`). Illegal transitions (such as a late Failed callback attempting to overwrite a Successful payment) are rejected and emit `PaymentStateConflict`.

### 4. Reconciliation and Webhook Intake
- Dispatched payments are swept and verified automatically via `paysasa:reconcile` and single-shot `VerifyPaymentStatusJob`.
- Unmatched webhooks are tracked and reviewable via `php artisan paysasa:webhooks:unmatched`.
- In production, M-Pesa webhooks fail-closed if `MPESA_WEBHOOK_IP_ALLOWLIST` is unconfigured unless an opaque secret route alias is configured.

