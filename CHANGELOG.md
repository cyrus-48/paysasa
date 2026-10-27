# Changelog

All notable changes to `cyrus-48/paysasa` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
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
