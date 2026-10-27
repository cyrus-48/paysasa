# Configuration Guide

All configuration lives in `config/paysasa.php` after publishing. Every value is `env()`-backed so `.env` is the normal place to set it per-environment.

## Laravel 13

`composer.json` constrains `illuminate/support`, `illuminate/database`, `illuminate/queue`, and `illuminate/http` to `^11.0|^12.0|^13.12` — note the `13.12` floor rather than a plain `^13.0`. Every `laravel/framework` release between `13.0.0` and `13.11.x` is affected by at least one of three published security advisories (a signed-URL path-confusion issue fixed in 13.12.0, and a CRLF-injection issue in the email validation rule fixed in 13.10.0 — see `composer.json`'s `config.audit.ignore` block for the advisory IDs and reasoning). Requiring `^13.12` means Composer will never resolve an affected version for the 13.x line; there's nothing to configure here, just don't loosen that floor when upgrading `composer.json` yourself.

If your own project's `composer audit`/CI still flags those three advisory IDs, that's because `audit.ignore` in composer.json only suppresses them for *this package's* resolution — copy the same `config.audit.ignore` block (or just make sure your own `laravel/framework` requirement is `^13.12` too) so your project-level audit isn't flagging a version range you can't actually land on anyway.

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

`client_id`, `client_secret`, `country` (default `KE`), `currency` (default `KES`), `callback_url`, `webhook_secret`.

### `drivers.stripe`

`public_key`, `secret_key`, `webhook_secret`, `api_version` (default `2024-06-20`).

### `drivers.pesapal`

`consumer_key`, `consumer_secret`, `ipn_url`.

### `drivers.flutterwave`

`public_key`, `secret_key`, `encryption_key`, `webhook_secret_hash`, `base_url`.

### `drivers.paystack`

`public_key`, `secret_key`, `base_url`.

### Google Pay / Apple Pay

There is no wallet driver. Google Pay and Apple Pay are client-side tokenization layers over the card networks: your frontend exchanges the wallet credential for a processor payment-method token, and you charge it through a card driver such as `stripe`. See [`04-architecture.md`](04-architecture.md#digital-wallets-google-pay--apple-pay).

### `drivers.pesalink` (experimental)

`institution_code`, `api_key`, `api_secret`, `webhook_secret`, `base_urls.sandbox` / `base_urls.production`. Kenyan bank rails have no single public API standard — configure this against your acquiring bank's actual endpoint, or extend `AbstractBankingDriver` for a specific bank. See [`06-driver-development-guide.md`](06-driver-development-guide.md).

## Multi-merchant overrides

Anything above can be overridden **per merchant** at runtime via the `provider_accounts` table instead of `.env`, using `Payment::forMerchant($merchantId)->driver('mpesa')->...`. See [`04-architecture.md#multi-merchant-credential-resolution`](04-architecture.md).
