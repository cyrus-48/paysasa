# Webhook Guide

## Endpoint

Every provider posts to the same pattern: `{APP_URL}/{config('paysasa.webhook_route_prefix')}/{provider}`, e.g.:

```
https://yourapp.test/paysasa/webhooks/mpesa
https://yourapp.test/paysasa/webhooks/airtel
https://yourapp.test/paysasa/webhooks/tkash
https://yourapp.test/paysasa/webhooks/stripe
https://yourapp.test/paysasa/webhooks/pesapal
https://yourapp.test/paysasa/webhooks/flutterwave
https://yourapp.test/paysasa/webhooks/paystack
https://yourapp.test/paysasa/webhooks/pesalink
https://yourapp.test/paysasa/webhooks/eft
https://yourapp.test/paysasa/webhooks/rtgs
https://yourapp.test/paysasa/webhooks/virtual_account
```

The route accepts both `GET` and `POST` (Pesapal's IPN can be either, depending on how you configure it) and is registered without CSRF protection — see `routes/paysasa.php`.

## Per-provider signature verification

| Provider | Scheme | Verifier |
|---|---|---|
| M-Pesa | No signing; IP allowlist (production) | `Verifiers\MpesaSignatureVerifier` |
| Stripe | `Stripe-Signature: t=...,v1=HMAC-SHA256("{t}.{body}", secret)`, ±5min replay window | `Verifiers\StripeSignatureVerifier` |
| Paystack | `x-paystack-signature: HMAC-SHA512(body, secret_key)` | `Verifiers\PaystackSignatureVerifier` |
| Flutterwave | `verif-hash` header, constant-time equality against dashboard-configured secret | `Verifiers\FlutterwaveSignatureVerifier` |
| Pesapal | No signature; re-verifies server-to-server via `GetTransactionStatus` | `Verifiers\PesapalSignatureVerifier` |
| Airtel / T-Kash / banking rails | `HMAC-SHA256(body, webhook_secret)` in a configurable header | `Verifiers\HmacHeaderSignatureVerifier` |

Every verifier throws `Exceptions\WebhookVerificationException` on failure, which `WebhookController` catches and turns into a `401` plus a `webhooks` row with `status = verification_failed` — **never** a silent drop, since that row is your evidence trail if you're ever investigating a spoofing attempt.

## M-Pesa C2B URL registration

Daraja requires validation/confirmation URLs be **registered** against your shortcode before it will call them — this is a one-time (per shortcode, per environment) setup step, not something that happens automatically on deploy:

```bash
php artisan paysasa:mpesa:register-urls
```

## Local development

Providers can't reach `localhost`. Use a tunnel (`ngrok http 8000`, Expose, Cloudflare Tunnel) and point `MPESA_STK_CALLBACK_URL` / `STRIPE_WEBHOOK_SECRET`'s dashboard config / etc. at the tunnel's public HTTPS URL. For Stripe specifically, `stripe listen --forward-to localhost:8000/paysasa/webhooks/stripe` is usually more convenient than a tunnel and gives you a `whsec_...` printed directly to your terminal.

## Replay / duplicate protection

`WebhookController::isDuplicate()` fingerprints the payload (`sha256` of the decoded JSON) and checks for a matching, already-`processed` webhook from the same provider within the last 10 minutes — a provider's own retry of an unacknowledged webhook, or a captured-and-replayed request, is detected and short-circuited to `status = ignored` with a `200` response (so the provider stops retrying) without reprocessing it. This is in addition to, not instead of, signature verification — a replayed request has a *valid* signature (it's a real captured request), which is exactly why timestamp-window checks (Stripe) and payload-fingerprint dedup exist as separate layers.

## Processing is asynchronous

`WebhookController` never calls the driver directly — it persists the (verified) webhook, dispatches `Jobs\DispatchWebhookJob` onto `config('paysasa.queue.webhooks_queue')`, and returns `200` immediately. This matters because most providers (Daraja included) will disable a callback URL that responds too slowly or too inconsistently. If your queue worker is down, webhooks queue up rather than time out — but do keep a worker running in production; nothing processes without one.

## Debugging a specific webhook

Every inbound webhook is a row in the `webhooks` table — `signature_valid`, `status`, `error_message`, and the full `payload`/`headers` JSON are all there:

```php
Webhook::where('provider', 'mpesa')->where('status', 'verification_failed')->latest()->first();
```

See [`11-troubleshooting.md`](11-troubleshooting.md) for common failure patterns.
