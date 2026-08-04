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
composer update paysasa/payments
php artisan vendor:publish --tag=paysasa-config --force  # review the diff before overwriting your customized config
php artisan vendor:publish --tag=paysasa-migrations
php artisan migrate
```

Always diff a freshly-published `config/paysasa.php` against your own before force-overwriting — new provider config blocks or settings are additive, but a `--force` publish replaces the whole file.

## Upgrading between major versions

Each major version ships an `UPGRADE-{version}.md` at the package root (not yet applicable — this is the 1.0 baseline) with:

1. A list of removed/renamed public API surface, with the direct replacement.
2. Any required data migration for renamed/restructured columns, shipped as an idempotent Artisan command (`paysasa:upgrade-vN`) rather than expecting you to write it yourself.
3. Config keys that changed shape, with before/after examples.

## This baseline (1.0)

Nothing to migrate from — this is the initial architecture. Once 1.0 ships, the enum-based `PaymentProvider` and `PaymentStatus` closed sets are the most likely source of a future breaking change if a genuinely new provider category or status is needed that doesn't fit the existing cases; that's a deliberate, documented trade-off of using backed enums for type safety (see [`06-driver-development-guide.md`](06-driver-development-guide.md#adding-webhook-support-for-your-driver)) and is flagged here so it isn't a surprise later.
