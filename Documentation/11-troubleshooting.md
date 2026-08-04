# Troubleshooting

### `DriverNotFoundException: Payment driver [x] is not registered`

`config('paysasa.drivers.x')` doesn't exist, or its `driver` key is missing. Check the key name matches exactly (`mpesa`, not `m-pesa` or `Mpesa`) and that you republished config after upgrading (`php artisan vendor:publish --tag=paysasa-config --force`, then re-apply your `.env` overrides — publishing doesn't touch `.env`).

### `InvalidConfigurationException: Driver [x] is missing required configuration: ...`

Run `php artisan paysasa:status` to see exactly which driver and which keys. Almost always a missing `.env` value — check the key list in [`02-configuration.md`](02-configuration.md) against your `.env`.

### STK push accepted but no callback ever arrives

1. Is `MPESA_STK_CALLBACK_URL` a publicly reachable HTTPS URL? Daraja cannot call `localhost` or an internal/private IP.
2. Is a queue worker running? The webhook itself is received and acknowledged synchronously, but check `webhooks` table for a row with `provider=mpesa` — if there's no row at all, the callback never reached your app (check the tunnel/firewall); if there's a row but `status` stays `received`, your queue worker isn't running.
3. Sandbox STK pushes to non-test numbers silently do nothing — use Safaricom's published sandbox test MSISDN (`254708374149`) against `MPESA_ENV=sandbox`.

### `WebhookVerificationException` / webhooks returning 401

- **Stripe**: confirm `STRIPE_WEBHOOK_SECRET` matches the *specific* endpoint's signing secret from the Stripe Dashboard (each endpoint has its own secret) — or, in local dev, the secret printed by `stripe listen`.
- **Paystack/Flutterwave**: confirm you're using the secret **key**, not the public key, for signature verification (`PaystackSignatureVerifier` hashes with `secret_key`).
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
