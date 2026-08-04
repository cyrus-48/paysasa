# Roadmap

## Shipped in this baseline (1.0)

- Unified driver architecture across mobile money, cards, wallets, and banking abstractions.
- 13 drivers: M-Pesa, Airtel Money, T-Kash, Stripe, Pesapal, Flutterwave, Paystack, Google Pay, Apple Pay, PesaLink, EFT, RTGS, Virtual Accounts.
- Full persistence layer (9 tables), event-driven lifecycle, queue-based webhook/verification processing.
- `Payment::fake()` testing support, `paysasa:status`/`paysasa:install`/`paysasa:mpesa:register-urls` Artisan commands.
- Multi-merchant credential resolution via `provider_accounts`.

## Near-term

- **Recurring billing / subscriptions** as a first-class concept (`Models\Subscription`, `Jobs\ProcessRecurringChargeJob` on a schedule) — currently a caller can build this themselves on top of saved `payment_methods` + the Laravel scheduler, but it isn't packaged.
- **QR payment support** for providers that offer a scan-to-pay flow (Pesapal and several bank apps support KE QR standards) — a `QrGeneratable` capability interface plus a `qrCode()` builder method.
- **Split/marketplace payments as a tracked concept**, not just a `splits` metadata array — a `payment_splits` table and `SplitPaymentProcessed` event so a marketplace's sub-merchant payouts are queryable the same way refunds are.
- **First-party Livewire/Blade components** for a drop-in "STK push status" polling widget and a hosted-checkout redirect helper, reducing frontend boilerplate for the most common flows.

## Under consideration

- **Additional bank integrations** shipped as concrete `AbstractBankingDriver` subclasses once a specific bank partnership/public API justifies maintaining it in core, rather than leaving every bank as a "bring your own subclass" exercise.
- **A hosted status dashboard** (Filament/Nova-agnostic, or a standalone package) for browsing `payments`/`webhooks`/`payment_logs` without writing raw queries — likely a separate `paysasa/dashboard` package rather than bloating the core payment library with an admin UI dependency.
- **Async/reactive payment status via server-sent events** as an alternative to the existing Laravel Echo broadcast channel, for apps that don't already run a WebSocket layer.
- **T-Kash driver hardening** once Telkom's integration pack details stabilize publicly (currently best-effort — see the caveat in `src/Drivers/MobileMoney/TKashDriver.php`).

## Explicitly out of scope

- **A hosted PCI-compliant card vault.** Card tokenization is delegated to the processor (Stripe, Paystack, etc.) by design — Paysasa will not add raw card number handling, which would defeat the PCI-scope-reduction goal described in [`08-security.md`](08-security.md).
- **Currency conversion / FX rate management.** Multi-currency support means "charge in the currency the provider was given," not automatic conversion — that's a treasury/accounting concern belonging in the host application or a dedicated FX package.

Contributions toward any near-term or under-consideration item are welcome — see [`13-contributing.md`](13-contributing.md), and open an issue to discuss design before a large PR.
