# Upgrade Guide

## Upgrading to 1.0 (from 0.x / pre-1.0)

### Package Identity
Ensure your `composer.json` references `cyrus-48/paysasa`:

```bash
composer require cyrus-48/paysasa
```

### Driver Removals
- **Google Pay & Apple Pay:** Remove calls to `Payment::driver('google_pay')` and `Payment::driver('apple_pay')`. Pass payment method tokens generated from Google Pay / Apple Pay directly to your card driver (e.g., `Payment::driver('stripe')->paymentMethod($token)->charge()`).
- **T-Kash:** Telkom Kenya integration has been removed from core.
- **EFT, RTGS, Virtual Accounts:** Removed phantom bank drivers. Use `Payment::driver('pesalink')` or create bank-specific drivers extending `AbstractBankingDriver`.

### Money Safety
When creating charges:
- Fractional amounts for whole-unit currencies (e.g. `KES 100.50`) will throw `InvalidAmountException` instead of being silently truncated to 100.
- Use integer units or valid minor amounts.

### Migrations
Publish and run the new hardening migration:

```bash
php artisan vendor:publish --tag=paysasa-migrations
php artisan migrate
```

### Reconciliation Schedule
Ensure Laravel's scheduler is running:
`paysasa:reconcile` automatically sweeps and verifies pending payments every minute without requiring manual cron setup if auto-registration is enabled.
