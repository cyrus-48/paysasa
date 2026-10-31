# Changelog

All notable changes to `cyrus-48/paysasa` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **`php artisan paysasa:doctor`** diagnoses the misconfigurations behind most "it doesn't work" reports, for each driver that has credentials (plus the default driver): missing settings, sandbox/production and test/live key mismatches, callback URLs a provider cannot use (not HTTPS, local or private hosts, wrong route, plain route despite an opaque alias), webhook verification that cannot succeed (missing signing secret; unsigned providers in production with no allowlist or alias), a live credential check against Daraja, Airtel, Pesapal, Stripe and Flutterwave, a side-effect-free reachability ping of your own callback URLs, and recent webhooks that failed verification. It also checks the environment (tables, routes, queue connection, cache store, TLS, scheduler, payments stuck pending). Options: `--offline`, `--no-probe`, `--json`, `--strict`; it exits non-zero on failures, so it can gate a deploy. See `Documentation/11-troubleshooting.md`.
- `WebhookVerificationException::reason()` (with `INVALID_SIGNATURE`, `MISSING_SIGNATURE_HEADER`, `MISSING_SECRET` and `MISCONFIGURED` constants) so tooling can tell a verifier that is working from one that cannot work until configuration is fixed.
- The webhook route answers a `paysasa:doctor` ping (a `GET` carrying an `X-Paysasa-Doctor` header with an HMAC of the route segment keyed by `APP_KEY`) before storing or verifying anything. A request with that header and a wrong token gets a `403`, and neither creates a webhook row.

### Fixed
- The README documented `php artisan paysasa:status INV-1001` (inspecting a payment); the command takes no argument and checks driver configuration.

### Removed
- **The Paystack driver** (`PaystackDriver`, `PaystackClient`, `PaystackSignatureVerifier`), the `paystack` entry in `config/paysasa.php`, the `PaymentProvider::Paystack` case, the `/paysasa/webhooks/paystack` webhook handling, and all Paystack documentation. It never left beta, and the project is focusing on M-Pesa, Stripe and the remaining drivers.

  **Upgrade note (breaking):** `Payment::$provider` is cast to the `PaymentProvider` enum, so after upgrading any stored payment with `provider = 'paystack'` can no longer be loaded through the Eloquent models (it throws a `ValueError`), including by the `paysasa:reconcile` sweep if such a payment is still pending. If you used the Paystack driver, **before upgrading**: let in-flight Paystack payments reach a terminal state, and export or archive (then delete) your `paysasa` rows where `provider = 'paystack'`. Also remove `PAYSTACK_*` from your `.env`, and point the Paystack dashboard webhook elsewhere (the route now returns 404 for `paystack`).

## [0.9.1] - 2026-10-28

### Security
- Webhook verifiers now **fail closed** when their signing secret is not configured. `hash_hmac()` accepts an empty key, so with `STRIPE_WEBHOOK_SECRET`, `PAYSTACK_SECRET_KEY`, `AIRTEL_WEBHOOK_SECRET` or `PESALINK_WEBHOOK_SECRET` unset, anyone could sign a forged webhook with an empty key and have it verify (Flutterwave likewise accepted an empty `verif-hash`). These verifiers now reject every request with a `WebhookVerificationException` until a secret is set. **Upgrade note:** make sure the secret for each provider you receive webhooks from is configured.

- M-Pesa STK success callbacks are now **confirmed with Daraja** (STK query) before a payment is settled (`drivers.mpesa.confirm_callbacks`, default `true`), because Daraja callbacks are unsigned. A forged success callback can no longer mark an unpaid payment as paid; if Daraja disagrees, its answer is used. New `Contracts\ConfirmsCallbacks` lets other drivers with unsigned callbacks opt in. Set `MPESA_CONFIRM_CALLBACKS=false` to restore the old behaviour.

### Fixed
- Webhook rate limiting no longer throttles the M-Pesa allowlisted IPs (or the new `rate_limiting.webhooks.trusted_ips`), so a busy merchant's real Safaricom callbacks are not dropped with a 429.
- Stripe and Paystack amounts are converted with the currency's real exponent instead of a hard-coded `/ 100` (and, for Stripe refunds and partial captures, a hard-coded KES), which was wrong for zero-decimal currencies such as RWF and made the amount-mismatch check misfire. `RefundRequest` gained an optional `currency`, set automatically by `ProcessRefund`.
- Refunds (`ProcessRefund`) are now safe: the refundable balance is enforced under a row lock and reserved by a pending `Refund` row before the provider is called, so a refund can no longer exceed the amount paid (including across several partial refunds or concurrent requests); only `successful` / `partially_refunded` payments can be refunded (`PaymentNotRefundableException`); the status becomes `refunded` only once completed refunds add up to the full amount; a "full" refund after a partial one asks the provider for the remaining balance; a definite provider rejection (4xx) releases the reservation while a timeout or 5xx keeps it, since the refund may have gone through.
- `FluentPaymentBuilder::refund()` now finds the payment by its `uuid` (the id returned as `transactionId`), then merchant reference, then provider reference, and throws a clear `PaymentNotRefundableException` when none match. Previously a `uuid` matched nothing and the call failed with a `TypeError`.
- A charge the driver rejects before contacting the provider (invalid phone number, invalid amount, missing configuration, unsupported operation) now marks its payment `failed` instead of leaving an orphaned `pending` row that an idempotent retry would replay as pending. The exception is still thrown to the caller.
- `EnsureIdempotencyKey` now applies to every state-changing method (not just `POST`) and rejects a blank header value.
- `StripeSignatureVerifier` accepts any of several `v1` signatures in the header, as Stripe sends while a webhook secret is being rotated.
- `IdempotencyManager` no longer catches every `Throwable` around the locked section and re-runs the charge callback. A failing charge now surfaces its own exception instead of being replayed as a "pending" response for a payment that never reached the provider. Lock fallback now applies only when the lock cannot be acquired, and the lock is always released.
- An amount-mismatch callback can no longer downgrade a settled payment: the hold now runs under a row lock, only moves `Pending` payments to `Processing`, and leaves terminal payments untouched (still flagging and reporting the mismatch). Amounts are compared in minor units, and only for success callbacks.
- `VerifyPaymentStatusJob` now asks the provider before expiring a payment. Previously a payment past `expires_at` was marked `Expired` without a final check, so a customer who paid just before the deadline could end up `Expired`. Connection errors during verification are handled like provider errors.
- `TransitionPayment` no longer throws `ArgumentCountError` when a payment moves to `Refunded`, `PartiallyRefunded` or `Reversed` (the events were constructed without their required `response` argument).
- New payments now get `expires_at` at creation (`paysasa.pending_ttl_minutes`, default 60; M-Pesa 15). Previously it was only set after a network timeout, so abandoned checkouts were re-verified every minute indefinitely.
- The webhook route constraint now allows digits, uppercase letters and hyphens, so opaque `webhook_aliases` slugs are actually routable.
- `DispatchWebhookJob::failed()` marks the webhook `failed` with the error once retries are exhausted, instead of leaving it `received`.

### Changed
- `paysasa:reconcile` backs off for long-unresolved payments (1 min, then 5, then 15) and is scheduled `withoutOverlapping()`.
- `webhook_aliases`, `webhooks.max_body_bytes`, `pending_ttl_minutes`, `schedule.enabled`, and the M-Pesa `pending_ttl_minutes` / `initial_verify_delay` keys are now declared in `config/paysasa.php` and documented.
- Configuration docs no longer describe the removed Google Pay, Apple Pay, EFT, RTGS and virtual-account drivers.

## [0.9.0] - 2026-10-12

### Added
- Centralized state transition engine (`TransitionPayment`) with atomic transactions, row-level locking (`lockForUpdate()`), and `DB::afterCommit()` event dispatching.
- Value objects: `Money` (integer-minor representation preventing floating point truncation) and `KenyanMsisdn` (validates and normalizes 07xx, 01xx, 254... formats).
- Anomaly and event system: `PaymentStateConflict`, `WebhookUnmatched`, `PaymentAmountMismatch`, `TransitionResult`.
- Reconciliation sweep command `paysasa:reconcile` and single-shot `VerifyPaymentStatusJob` with idempotency and exponential backoff.
- Unmatched webhooks reporting command `paysasa:webhooks:unmatched`.
- Hardening database migration (`2025_01_01_000010_update_payments_and_webhooks_tables.php`) introducing `dedupe_key`, `match_attempts`, `last_checked_at`, `check_count`, `outcome_uncertain`, and `flags`.
- Network timeout resilience in `InitiatePayment` marking payments as pending with `outcome_uncertain = true` when transport fails.
- Fail-closed M-Pesa webhook verification in production if IP allowlist is empty without an opaque route alias.
- 64KB body size limit and raw-payload SHA-256 deduplication on inbound webhooks.
- CI matrix workflow for PHP 8.2-8.4 across Laravel 11-13 in `.github/workflows/ci.yml`.
- `SECURITY.md` vulnerability reporting guidelines.

### Changed
- Unified package naming across Composer and documentation to `cyrus-48/paysasa`.
- Webhook-settled payments now reliably trigger `PaymentSuccessful` lifecycle events.
- Safaricom Daraja M-Pesa driver hardened with `DarajaResultMap`, result code mapping (`0` -> Successful, `1032` -> Cancelled, `1037` -> Expired), "still processing" error catching in `verify()`, positive amount validation in `reverse()`, and security credential in-memory caching.
- Digital wallet support (Google Pay and Apple Pay) refactored into a direct card token recipe using `StripeDriver`.

### Removed
- Removed unproven and phantom drivers: `GooglePayDriver`, `ApplePayDriver`, `TKashDriver`, `EftDriver`, `RtgsDriver`, `VirtualAccountDriver`, and `VirtualAccountProvider` contract.
