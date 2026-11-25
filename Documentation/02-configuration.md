# Configuration Guide

All configuration lives in `config/paysasa.php` after publishing. Every value is `env()`-backed so `.env` is the normal place to set it per-environment.

## Laravel and dependency version floors

`composer.json` constrains the `illuminate/*` packages to `^12.73|^13.43`, `guzzlehttp/guzzle` to `^7.17|^8.6`, `nesbot/carbon` to `^2.73|^3.15.1` and `ramsey/uuid` to `^4.10`. Each minimum is the latest release of that line when it was last reviewed, which contains the fixes for every security advisory published against it at the time (see the Security section of the [CHANGELOG](../CHANGELOG.md)), so Composer can never resolve a known-vulnerable version for an application that installs Paysasa. There is nothing to configure; just don't loosen the floors when editing `composer.json` yourself, and keep your own application's `laravel/framework` requirement at or above them.

Laravel 11 is not supported: it stopped receiving security fixes on 12 March 2026, so advisories published after that date are not fixed there. Upgrade to Laravel 12 or 13.

The repository's `audit` workflow runs `composer audit` weekly, against both the newest and the *oldest* versions the constraints allow, so a new advisory (or a floor that has fallen behind) shows up without waiting for a push.

## Global settings

| Key | Env var | Default | Purpose |
|---|---|---|---|
| `default` | `PAYSASA_DEFAULT_DRIVER` | `mpesa` | Driver used by `Payment::amount(...)` without an explicit `->driver()` call. |
| `environment` | `PAYSASA_ENV` | `sandbox` in non-production `APP_ENV` | Global sandbox/production switch; individual drivers can still override via their own `env` key. |
| `currency` | `PAYSASA_CURRENCY` | `KES` | Default currency for the fluent builder. |
| `callback_base_url` | `PAYSASA_CALLBACK_URL` | `APP_URL` | Base host providers call back to. |
| `webhook_route_prefix` | `PAYSASA_WEBHOOK_PREFIX` | `paysasa/webhooks` | Route prefix — full webhook URL is `{APP_URL}/{prefix}/{provider}`. |

## Idempotency

| Key | Env var | Default |
|---|---|---|
| `idempotency.enabled` | `PAYSASA_IDEMPOTENCY_ENABLED` | `true` |
| `idempotency.ttl_seconds` | `PAYSASA_IDEMPOTENCY_TTL` | `86400` (24h) |
| `idempotency.store` | `PAYSASA_IDEMPOTENCY_STORE` | your default cache store |

See [`08-security.md#idempotency`](08-security.md#idempotency) for the semantics.

## HTTP client

| Key | Env var | Default |
|---|---|---|
| `http.timeout` | `PAYSASA_HTTP_TIMEOUT` | `30` seconds |
| `http.connect_timeout` | `PAYSASA_HTTP_CONNECT_TIMEOUT` | `10` seconds |
| `http.retry.times` | `PAYSASA_HTTP_RETRY_TIMES` | `3` |
| `http.retry.sleep_milliseconds` | `PAYSASA_HTTP_RETRY_SLEEP` | `500` |
| `http.verify_ssl` | `PAYSASA_HTTP_VERIFY_SSL` | `true` — never disable in production |

## Queue

| Key | Env var | Default |
|---|---|---|
| `queue.connection` | `PAYSASA_QUEUE_CONNECTION` | your default queue connection |
| `queue.queue` | `PAYSASA_QUEUE_NAME` | `payments` |
| `queue.webhooks_queue` | `PAYSASA_WEBHOOKS_QUEUE` | `payment-webhooks` |
| `queue.tries` | `PAYSASA_JOB_TRIES` | `5` |
| `queue.backoff` | — (array, edit config directly) | `[10, 30, 60, 300, 900]` seconds |
| `queue.retry_until_minutes` | `PAYSASA_JOB_RETRY_UNTIL_MINUTES` | `60` |

## Logging

| Key | Env var | Default |
|---|---|---|
| `logging.enabled` | `PAYSASA_LOGGING_ENABLED` | `true` |
| `logging.channel` | `PAYSASA_LOG_CHANNEL` | your default log channel |
| `logging.log_raw_payloads` | `PAYSASA_LOG_RAW_PAYLOADS` | `true` |
| `logging.redact_fields` | — (array) | `pin, password, card_number, cvv, account_number, authorization_code` |

## Webhooks

| Key | Env var | Default | Purpose |
|---|---|---|---|
| `webhooks.max_body_bytes` | `PAYSASA_WEBHOOK_MAX_BODY_BYTES` | `65536` | Larger requests are rejected with `413` before anything is stored. |
| `webhook_aliases` | `PAYSASA_MPESA_WEBHOOK_ALIAS` | none | Map a random slug to a provider (e.g. `['k3j9x2a7' => 'mpesa']`) and register `{prefix}/k3j9x2a7` with Safaricom. |
| `drivers.mpesa.webhook_ip_allowlist` | `MPESA_WEBHOOK_IP_ALLOWLIST` | none | Comma-separated Safaricom source IPs. |

Daraja does not sign its callbacks. In `production`, M-Pesa webhooks are rejected unless they arrive on an opaque alias **or** an IP allowlist is configured. Generate a slug with `php -r "echo bin2hex(random_bytes(12));"`.

## Pending payments and reconciliation

| Key | Env var | Default | Purpose |
|---|---|---|---|
| `pending_ttl_minutes` | `PAYSASA_PENDING_TTL_MINUTES` | `60` | How long a payment may stay `pending`/`processing` before it is expired (after one last check with the provider). |
| `drivers.mpesa.pending_ttl_minutes` | `MPESA_PENDING_TTL_MINUTES` | `15` | Per-provider override. Any `drivers.{provider}.pending_ttl_minutes` works. |
| `drivers.mpesa.initial_verify_delay` | `MPESA_INITIAL_VERIFY_DELAY` | `45` | Seconds before the first status check of a pending STK push. |
| `schedule.enabled` | `PAYSASA_SCHEDULE_ENABLED` | `true` | Register `paysasa:reconcile` (every minute, without overlapping) in the host app's scheduler. Set `false` if you schedule it yourself. |

`paysasa:reconcile` backs off for payments that stay unresolved: every minute for the first 5 checks, every 5 minutes up to 15 checks, then every 15 minutes. A payment is always checked with the provider **before** it is expired, so a customer who paid just before the deadline is settled, not expired.

## Rate limiting

| Key | Env var | Default |
|---|---|---|
| `rate_limiting.webhooks.enabled` | `PAYSASA_WEBHOOK_RATE_LIMIT_ENABLED` | `true` |
| `rate_limiting.webhooks.max_attempts` | `PAYSASA_WEBHOOK_RATE_LIMIT_MAX` | `120` |
| `rate_limiting.webhooks.decay_seconds` | `PAYSASA_WEBHOOK_RATE_LIMIT_DECAY` | `60` |
| `rate_limiting.webhooks.trusted_ips` | `PAYSASA_WEBHOOK_TRUSTED_IPS` (comma-separated) | _none_ |

IPs in `trusted_ips` and the M-Pesa `webhook_ip_allowlist` are never throttled — Safaricom delivers every merchant's callbacks from a few shared IPs, and throttling them would drop real payment callbacks.

## Fraud checks

```php
'fraud_checks' => [
    App\Payments\FraudChecks\VelocityCheck::class,
],
```

Each listed class must implement `Paysasa\Payments\Contracts\FraudCheck`. Run in order before every charge; throwing `FraudSuspectedException` aborts it. See [`08-security.md#fraud-detection-hooks`](08-security.md#fraud-detection-hooks).

## Per-provider keys

### `drivers.mpesa`

| Key | Env var |
|---|---|
| `consumer_key` / `consumer_secret` | `MPESA_CONSUMER_KEY` / `MPESA_CONSUMER_SECRET` |
| `shortcode` | `MPESA_SHORTCODE` |
| `passkey` | `MPESA_PASSKEY` |
| `initiator_name` / `initiator_password` | `MPESA_INITIATOR_NAME` / `MPESA_INITIATOR_PASSWORD` |
| `security_credential` (or `certificate_path`) | `MPESA_SECURITY_CREDENTIAL` / `MPESA_CERTIFICATE_PATH` |
| `b2c_shortcode`, `b2c_queue_timeout_url`, `b2c_result_url` | `MPESA_B2C_*` |
| `stk_callback_url` | `MPESA_STK_CALLBACK_URL` |
| `c2b_validation_url`, `c2b_confirmation_url` | `MPESA_C2B_*` |
| `webhook_ip_allowlist` | `MPESA_WEBHOOK_IP_ALLOWLIST` (comma-separated) |
| `confirm_callbacks` (default `true`) | `MPESA_CONFIRM_CALLBACKS` |

Daraja callbacks are unsigned, so with `confirm_callbacks` on, an STK **success** callback is confirmed with an STK query before the payment is settled. If Daraja says the payment did not succeed, the provider's answer wins; if it cannot confirm yet, the job retries and the reconcile sweep is the backstop.

### `drivers.airtel`

`client_id`, `client_secret`, `country` (default `KE`), `currency` (default `KES`), `callback_url`, `webhook_ip_allowlist`, `confirm_callbacks`, `disbursement_pin`, `public_key`.

- **Currency.** Airtel debits in the currency configured here (`AIRTEL_CURRENCY`, sent as `X-Currency`). A charge or payout in any other currency throws `InvalidConfigurationException` before anything is sent, rather than silently debiting the wrong currency.
- **Phone numbers** are normalised and sent to Airtel without the country code (`0712345678` becomes `712345678`).
- **Callbacks are unsigned.** Airtel's callback carries no signature that Paysasa verifies, so there is no `webhook_secret`. In production you must set `AIRTEL_WEBHOOK_IP_ALLOWLIST` (comma-separated source IPs) and/or receive callbacks on a secret route alias (`PAYSASA_AIRTEL_WEBHOOK_ALIAS`); with neither, the endpoint refuses every request. Independently of that, with `confirm_callbacks` on (`AIRTEL_CONFIRM_CALLBACKS`, default `true`) a **success** callback is confirmed with Airtel's transaction enquiry before the payment is settled; if Airtel disagrees its answer wins, and if it cannot confirm yet the job retries.
- **Refunds** use Airtel's own transaction id (the `airtel_money_id`, stored as the payment's `receipt_number`) and Airtel refunds the **full** amount only: a partial refund throws `UnsupportedOperationException` and releases the reservation. A payment with no receipt number cannot be refunded.
- **Payouts** need `AIRTEL_DISBURSEMENT_PIN` and `AIRTEL_PUBLIC_KEY` (Airtel's RSA public key, PEM or bare base64); the PIN is RSA-encrypted (PKCS#1 v1.5) and base64-encoded before it is sent.
- Access tokens are cached for Airtel's reported `expires_in` (minus a 60 second margin), and a `401` triggers one token refresh and retry.
- **API paths and hosts are configurable.** Airtel versions its endpoints per market and product, and client libraries disagree, so the two that vary are settings: `AIRTEL_COLLECTION_PATH` (default `/merchant/v1/payments/`; some markets use `/merchant/v2/payments/`) and `AIRTEL_DISBURSEMENT_PATH` (default `/standard/v2/disbursements/`; v1 was `/standard/v1/disbursements/`). Country-specific hosts can be set with `AIRTEL_SANDBOX_URL` and `AIRTEL_PRODUCTION_URL`. Confirm the paths against your own sandbox before going live; a blank value falls back to the default.
- **`callback_url` is informational.** Paysasa does not send it with a collection request: Airtel callbacks go to the URL registered for your application in the Airtel developer portal, so register the Paysasa webhook route (or your secret alias) there. `paysasa:doctor` uses the setting only to check that the URL is reachable.
- **Statuses.** `TS` settles the payment, `TF` fails it, and `TE` is treated as expired; `TIP`, `TI`, `TA` and anything unrecognised stay pending. Airtel's public material does not document `TE`, so that mapping is unconfirmed.
- **Callback `hash` field.** Some Airtel callbacks carry a `hash`. Its algorithm and key are not documented publicly, so Paysasa does not rely on it; the enquiry confirmation above is what makes a spoofed success callback harmless.
- **Failures** carry Airtel's result code when it supplies one (for example `[ESB000014] Insufficient funds`), and a failed HTTP response includes the message from Airtel's error body, so the code can be looked up in Airtel's error table.

### `drivers.stripe`

`public_key`, `secret_key`, `webhook_secret`, `api_version` (default `2024-06-20`).

### `drivers.pesapal`

`consumer_key`, `consumer_secret`, `ipn_url`.

### `drivers.flutterwave`

`public_key`, `secret_key`, `encryption_key`, `webhook_secret_hash`, `base_url`.

### Google Pay / Apple Pay

There is no wallet driver. Google Pay and Apple Pay are client-side tokenization layers over the card networks: your frontend exchanges the wallet credential for a processor payment-method token, and you charge it through a card driver such as `stripe`. See [`04-architecture.md`](04-architecture.md#digital-wallets-google-pay--apple-pay).

### `drivers.pesalink` (experimental)

`institution_code`, `api_key`, `api_secret`, `webhook_secret`, `base_urls.sandbox` / `base_urls.production`. Kenyan bank rails have no single public API standard — configure this against your acquiring bank's actual endpoint, or extend `AbstractBankingDriver` for a specific bank. See [`06-driver-development-guide.md`](06-driver-development-guide.md).

## Multi-merchant overrides

Anything above can be overridden **per merchant** at runtime via the `provider_accounts` table instead of `.env`, using `Payment::forMerchant($merchantId)->driver('mpesa')->...`. See [`04-architecture.md#multi-merchant-credential-resolution`](04-architecture.md).
