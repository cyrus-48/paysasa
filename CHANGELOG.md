# Changelog

All notable changes to `cyrus-48/paysasa` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
