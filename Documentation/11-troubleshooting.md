# Troubleshooting

## Start with the doctor

```bash
php artisan paysasa:doctor            # every driver that has credentials, plus the default driver
php artisan paysasa:doctor stripe     # just one (or several) drivers
php artisan paysasa:doctor --offline  # config checks only, no network
```

Most "it doesn't work" reports are misconfiguration, and the doctor finds them in one pass. For each driver it checks:

| Check | What it catches |
|---|---|
| **credentials** | Required settings that are missing (and recommended ones, such as the STK callback URL). |
| **mode** | A driver in sandbox mode while the app runs in production (no real money moves), or live mode outside production. Also a missing base URL for the active mode. |
| **secret key / shortcode / passkey** | A Stripe test key in production (or a live key elsewhere), a mismatched public/secret key pair, a malformed webhook secret, the public Daraja sandbox shortcode or passkey in production, an unreadable M-Pesa certificate. |
| **URL (…)** | Callback URLs that are not absolute HTTPS URLs, point at localhost or a private address, are not accepted by `allowed_callback_hosts`, do not end in the Paysasa webhook route, or use the guessable route although an opaque alias is configured. For providers whose webhook URL is set in their dashboard (Stripe, Flutterwave, PesaLink) it prints the URL to register. |
| **webhook verification** | Runs the real verifier on an unsigned request. A signing secret that is not set (every webhook would answer 401) and, in production, unsigned providers (M-Pesa) with neither an IP allowlist nor a secret route alias are reported as failures. |
| **credential check** | A live call to the provider (an OAuth token for Daraja, Airtel and Pesapal; a read-only balance request for Stripe and Flutterwave). Rejected credentials (HTTP 400/401/403) are told apart from provider outages and from connection failures. |
| **reachability (…)** | Pings your callback URL from this server. It proves DNS, TLS, your proxy or WAF and the route all work from here. The ping is side-effect free: nothing is stored, and only a caller holding your `APP_KEY` can produce the token. |
| **recent webhooks** | Webhooks from the last 24 hours that failed verification (wrong secret, an allowlist that excludes the provider, the wrong route). |

It also runs a handful of environment checks: the tables exist, the webhook routes are registered, the callback base URL is public HTTPS in production, the queue connection is real (`null` discards jobs, `sync` cannot retry), the cache store persists between requests (idempotency keys and OAuth tokens live there), TLS verification is on, the scheduler is enabled, and no payments are stuck pending, which is the usual symptom of a dead queue worker or scheduler.

Options: `--no-probe` skips pinging your own URLs, `--json` prints machine-readable output, and `--strict` also exits non-zero on warnings. The command exits non-zero when anything fails, so it can gate a deploy: `php artisan paysasa:doctor --strict` in CI or a release script.

**What it cannot tell you:** whether the provider's servers can reach yours. If your firewall only admits provider IP ranges, or the provider needs the callback registered on their side (Daraja C2B URLs, Pesapal IPN), confirm that with a sandbox payment. The reachability check only covers your side of the connection. The live credential check also needs outbound HTTPS to the provider, which some hosts block.

### `DriverNotFoundException: Payment driver [x] is not registered`

`config('paysasa.drivers.x')` doesn't exist, or its `driver` key is missing. Check the key name matches exactly (`mpesa`, not `m-pesa` or `Mpesa`) and that you republished config after upgrading (`php artisan vendor:publish --tag=paysasa-config --force`, then re-apply your `.env` overrides — publishing doesn't touch `.env`).

### `InvalidConfigurationException: Driver [x] is missing required configuration: ...`

Run `php artisan paysasa:doctor` to see exactly which driver and which keys. Almost always a missing `.env` value — check the key list in [`02-configuration.md`](02-configuration.md) against your `.env`.

### STK push accepted but no callback ever arrives

1. Is `MPESA_STK_CALLBACK_URL` a publicly reachable HTTPS URL? Daraja cannot call `localhost` or an internal/private IP.
2. Is a queue worker running? The webhook itself is received and acknowledged synchronously, but check `webhooks` table for a row with `provider=mpesa` — if there's no row at all, the callback never reached your app (check the tunnel/firewall); if there's a row but `status` stays `received`, your queue worker isn't running.
3. Sandbox STK pushes to non-test numbers silently do nothing — use Safaricom's published sandbox test MSISDN (`254708374149`) against `MPESA_ENV=sandbox`.

### `WebhookVerificationException` / webhooks returning 401

- **Stripe**: confirm `STRIPE_WEBHOOK_SECRET` matches the *specific* endpoint's signing secret from the Stripe Dashboard (each endpoint has its own secret) — or, in local dev, the secret printed by `stripe listen`.
- **Flutterwave**: `FLUTTERWAVE_WEBHOOK_SECRET_HASH` must equal the "secret hash" you set in the Flutterwave dashboard (sent back as the `verif-hash` header) — it is not your API secret key.
- **M-Pesa**: if you've populated `MPESA_WEBHOOK_IP_ALLOWLIST`, confirm Safaricom's current published source IPs match — these do occasionally change; check Daraja's developer portal for the current list, or temporarily clear the allowlist to confirm this is the cause before re-populating it correctly.
- Check the `webhooks` table row: `error_message` on a `verification_failed` row tells you exactly which check failed.

### `IdempotencyConflictException` on what looks like a legitimate retry

The same `idempotencyKey` was reused with a different `amount`, `currency`, `phone`, `reference`, or `paymentMethodId` than the original call. Generate a **new** idempotency key per distinct payment attempt — don't reuse one key across genuinely different charges (e.g. don't key it off `$user->id` alone; key it off something unique to *this* checkout attempt, like a cart/session ID).

### `ProviderApiException` on every request to a specific provider

Check `$exception->statusCode()` and `$exception->rawResponse()` — for OAuth-based providers (Daraja, Airtel, Pesapal) a `401`/`invalid_grant` almost always means the consumer key/secret pair is for the wrong environment (sandbox key against the production base URL, or vice versa) — confirm `config('paysasa.drivers.x.env')` matches which key/secret pair you actually have.

### Amounts look wrong (off by 100x, or fractional cents)

You're mixing major and minor units somewhere outside the package. Every `ChargeRequest`/`PaymentResponse` amount is in **major units** (2500.00, not 250000) — conversion to/from minor units for storage happens only inside `Models\Payment`'s `amount` accessor/mutator and each driver's own translation to its provider's expected unit. If you're reading `amount_minor` directly instead of the `amount` accessor, remember `Enums\Currency::minorUnitExponent()` is `0` for RWF and `2` for everything else — don't assume `/100` universally.

### Tests fail with `SQLSTATE... NOT NULL constraint failed: ...amount_minor`

If you've written your own repository/model logic that mass-assigns `amount` via `Model::create([...])`, ensure `amount` is listed in that model's `$fillable` array (`amount_minor` alone isn't enough — the virtual `amount` mutator that computes it needs to be an assignable attribute) and that `currency` is set *before* `amount` in the array literal, since PHP preserves array insertion order and `HasMinorUnitAmount::setAmountAttribute()` reads the currently-set `currency` attribute to pick the right minor-unit exponent.

### `paysasa:status` shows every driver as `MISCONFIGURED` immediately after install

Config caching (`php artisan config:cache`) from before you set your `.env` values is stale — run `php artisan config:clear` (or re-run `config:cache` after setting `.env`).
