# Contributing Guide

Paysasa aims to become the default payment layer for the Kenyan Laravel ecosystem — that only works if it stays maintainable as more providers and contributors join. These guidelines exist to keep the codebase coherent, not to gatekeep.

## Getting set up

```bash
git clone <your fork>
cd paysasa
composer install
cp phpunit.xml.dist phpunit.xml 2>/dev/null || true  # if you customize local test env vars
composer test
composer analyse   # Larastan, level 5, must pass clean (baseline covers pre-existing Eloquent dynamic-property noise)
composer format     # php-cs-fixer
```

## Before opening a PR

- [ ] `composer test` passes.
- [ ] `composer analyse` passes (or you've added a justified, minimal baseline entry — not a blanket ignore).
- [ ] New behaviour has a test. A driver change without a `Http::fake()`-backed test exercising the actual request/response shape won't be merged — see `tests/Unit/Drivers/MpesaDriverTest.php` and `StripeDriverTest.php` as the reference pattern.
- [ ] Public API changes are reflected in the relevant `Documentation/*.md` file, not just code comments.
- [ ] Config additions include an `env()` default and a doc-comment in `config/paysasa.php` explaining the key, following the existing style.

## Adding a new provider driver

Read [`06-driver-development-guide.md`](06-driver-development-guide.md) first. In short:

1. `src/Drivers/{Category}/YourDriver.php` extending `AbstractDriver`, implementing `PaymentDriver` + whichever capability interfaces apply.
2. `src/Services/{Provider}/YourClient.php` — raw HTTP client, provider-faithful field names, no Paysasa DTOs inside it.
3. A config block in `config/paysasa.php` under `drivers.{name}`, with every credential `env()`-backed.
4. If the provider supports webhooks: a `Webhooks\Verifiers\*` strategy (reuse `HmacHeaderSignatureVerifier` if it fits) and a case in `WebhookDispatcher::verifierFor()`.
5. Tests: at minimum, a successful charge, a rejected/failed charge, and (if applicable) webhook callback translation — mirroring `MpesaDriverTest.php`.
6. Add the provider to the table in the root `README.md` and to `02-configuration.md`.

## Coding standards

- PHP 8.2+ features are fine and encouraged (readonly properties, enums, first-class callable syntax) — this package doesn't support PHP 8.0/8.1.
- `declare(strict_types=1);` at the top of every file.
- Constructor property promotion where it doesn't hurt readability.
- No comments explaining *what* code does — name things clearly instead. Comments are reserved for *why* something non-obvious is the way it is (see almost any file in `src/` for the house style).
- Follow PSR-12; `composer format` (php-cs-fixer) enforces it automatically — don't hand-format.

## Reporting a security issue

**Do not open a public GitHub issue for a security vulnerability.** See `SECURITY.md` (or, until that file exists, open a private security advisory via GitHub's "Report a vulnerability" flow) — this is a payments package; a disclosed-in-the-open credential-handling or signature-verification bug is a live risk to every production deployment until patched.

## Governance

Until the project has a formal maintainer team, PRs are reviewed against: does it match the architecture in `Documentation/04-architecture.md`, does it maintain the "every driver returns the same `PaymentResponse` shape" invariant, and does it come with tests. Architectural deviations (a driver that bypasses `AbstractDriver`'s HTTP client, a new persistence path that skips `Contracts\PaymentRepository`) need a design discussion in an issue before a PR, not after.
