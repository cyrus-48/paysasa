# Installation Guide

## 1. Require the package

```bash
composer require paysasa/payments
```

Laravel package auto-discovery registers `PaysasaServiceProvider` and the `Payment` facade automatically (see `composer.json`'s `extra.laravel` block) — no manual provider registration needed on Laravel 11, 12, or 13.

## 2. Install

```bash
php artisan paysasa:install --migrate
```

This runs, in order:

1. `vendor:publish --tag=paysasa-config` → `config/paysasa.php`
2. `vendor:publish --tag=paysasa-migrations` → `database/migrations/*_create_payments_table.php` etc.
3. `migrate` (only with `--migrate`) → creates `payments`, `transactions`, `payment_attempts`, `refunds`, `webhooks`, `payment_logs`, `provider_accounts`, `payment_methods`, `audit_logs`.

Without `--migrate`, run `php artisan migrate` yourself when ready — useful if you want to review the published migrations first, or squash them into your own schema.

## 3. Set credentials

Add the credentials for whichever providers you actually use to `.env`. You do **not** need every provider configured — `paysasa:status` (step 5) only checks drivers you've referenced.

```dotenv
# M-Pesa (Daraja)
MPESA_ENV=sandbox
MPESA_CONSUMER_KEY=
MPESA_CONSUMER_SECRET=
MPESA_SHORTCODE=174379
MPESA_PASSKEY=
MPESA_STK_CALLBACK_URL="${APP_URL}/paysasa/webhooks/mpesa"

# Stripe
STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=

# ... see config/paysasa.php for the full list per provider
```

The complete key reference is in [`02-configuration.md`](02-configuration.md).

## 4. Point provider dashboards at your webhook URLs

Every provider calls back into `{APP_URL}/paysasa/webhooks/{provider}`, e.g.:

- `https://yourapp.test/paysasa/webhooks/mpesa`
- `https://yourapp.test/paysasa/webhooks/stripe`
- `https://yourapp.test/paysasa/webhooks/paystack`

For M-Pesa specifically, C2B additionally requires **registering** the validation/confirmation URLs before Safaricom will call them:

```bash
php artisan paysasa:mpesa:register-urls
```

See [`07-webhook-guide.md`](07-webhook-guide.md) for the full per-provider webhook setup, including local development (ngrok/expose) guidance.

## 5. Verify everything is wired correctly

```bash
php artisan paysasa:status
```

```
+------------+----------------+--------+
| Driver     | Status         | Detail |
+------------+----------------+--------+
| mpesa      | OK             |        |
| stripe     | OK             |        |
| pesapal    | MISCONFIGURED  | Driver [pesapal] is missing required configuration: consumer_key, consumer_secret. |
+------------+----------------+--------+
```

## 6. Start a queue worker

Webhook processing and status-verification retries run on the queue (`config('paysasa.queue.queue')`, default `payments`, and `payment-webhooks` for the dedicated webhook queue):

```bash
php artisan queue:work --queue=payments,payment-webhooks
```

In local development without a queue worker running, webhook jobs will sit in the queue's `sync`/database driver until processed — set `QUEUE_CONNECTION=sync` in `.env` for local testing if you don't want to run a worker.

## 7. First charge

```php
use Paysasa\Payments\Facades\Payment;

Payment::driver('mpesa')->amount(1)->phone('254708374149')->reference('TEST-1')->charge();
```

`254708374149` is Safaricom's published sandbox test MSISDN — safe to use against `MPESA_ENV=sandbox`.

## Uninstalling

```bash
php artisan vendor:publish --tag=paysasa-config --force  # to review, or just delete config/paysasa.php
composer remove paysasa/payments
```

The migrations you published remain — drop the tables yourself with a new migration if you're fully decommissioning the package, since Paysasa never auto-drops tables that might hold transaction history you need to retain for compliance.
