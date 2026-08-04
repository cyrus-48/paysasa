# Security Architecture

## Webhook signature validation / HMAC verification

Covered in full in [`07-webhook-guide.md`](07-webhook-guide.md). Every inbound webhook is verified by a provider-specific `Contracts\SignatureVerifier` **before** its payload is trusted for anything — `WebhookController` persists the raw payload first (for forensics), verifies second, and only hands a verified payload to a driver's `handleCallback()`.

## OAuth token management

`Services\Mpesa\DarajaAuthenticator`, `Services\Airtel\AirtelAuthenticator`, and Pesapal's inline token caching in `PesapalClient` all follow the same pattern: fetch once, cache in `config('cache.default')` for slightly less than the token's real TTL, refresh transparently on the next call after expiry. Tokens are never persisted to the database or logged — they live only in the cache store, which should itself be access-controlled the same as any other secret store (Redis with auth, not an open port).

## Encrypted credentials

`provider_accounts.credentials` uses Laravel's `AsEncryptedCollection` cast — encrypted with `APP_KEY` (AES-256-CBC) at rest, decrypted only in-process when `CredentialVault::resolve()` reads it, and marked `$hidden` on the model so it never accidentally serializes into an API response or `dd()` output. Rotate `APP_KEY` with `php artisan key:rotate` awareness that this **re-encrypts nothing automatically** — Laravel's standard caveat applies: decrypt-with-old-key, re-save, applies here too if you rotate keys.

`.env` credentials (the config-file path, not `provider_accounts`) are protected exactly as well as your `.env` file is — standard Laravel guidance applies (never commit it, restrict server file permissions, prefer a secrets manager like AWS Secrets Manager / Vault injecting env vars at deploy time over a checked-in `.env` in any shared environment).

## Idempotency keys

`Support\IdempotencyManager` wraps every `charge()` call: same `idempotencyKey` + same request fingerprint (hash of provider/amount/currency/phone/reference/paymentMethodId) within `config('paysasa.idempotency.ttl_seconds')` (default 24h) returns the **cached** original `PaymentResponse` without re-hitting the provider — protecting against a customer double-tapping "Pay," a frontend retry after a timeout, or a queued job replaying after a crash, all of which could otherwise produce duplicate charges. Reusing a key with a **different** payload throws `IdempotencyConflictException` rather than silently charging the new amount — that mismatch means the caller has a bug, and failing loudly is safer than guessing which payload was intended.

## Replay attack prevention

Two independent layers, deliberately: Stripe's signature scheme includes a timestamp and this package rejects anything older than 5 minutes (`StripeSignatureVerifier`); separately, `WebhookController::isDuplicate()` fingerprint-dedupes any provider's payload regardless of whether that provider's own scheme has replay protection built in.

## Request signing (outbound)

Provider-specific, implemented per driver: Daraja's `SecurityCredential` (RSA-encrypted initiator password via Safaricom's public certificate — see `MpesaDriver::securityCredential()`), Stripe/Paystack/Flutterwave's bearer-token auth, Pesapal's OAuth-style token exchange. See `04-architecture.md#authentication-flow`.

## Rate limiting

`Middleware\ThrottleWebhooks` rate-limits inbound webhook traffic per source IP (`config('paysasa.rate_limiting.webhooks')`, default 120 req/min) independent of whatever throttling the host app applies elsewhere — closes off the webhook endpoint as a DoS/probing vector even if an attacker doesn't know a valid signature.

## Fraud detection hooks

`Contracts\FraudCheck` + `config('paysasa.fraud_checks')` — a list of classes run, in order, before every charge reaches a driver:

```php
class VelocityCheck implements FraudCheck
{
    public function check(ChargeRequest $request): void
    {
        $recentCount = Payment::where('customer_phone', $request->phone)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->count();

        if ($recentCount > 5) {
            throw new FraudSuspectedException("Velocity limit exceeded for {$request->phone}");
        }
    }
}
```

This is a hook point, not a fraud engine — Paysasa doesn't ship device fingerprinting, ML scoring, or a blocklist service. Plug in your own logic, or a third-party fraud API call, here.

## Audit logging

`audit_logs` (via `Support\AuditLogger`) records sensitive, non-transactional actions — refund approvals, credential rotation — separately from `payment_logs` (system/API activity). Append-only at the application layer.

## PCI DSS considerations

Paysasa is designed so a host application **never handles a raw PAN, CVV, or full card number** server-side:

- Card charges go through `cardToken`/`paymentMethodId` — a token minted client-side (Stripe.js, Paystack's inline JS, etc.) that only the card network/processor can turn back into card data.
- `payment_methods.token` stores only the provider's vault reference.
- This keeps the host application's PCI scope at **SAQ A** (or SAQ A-EP depending on integration details) rather than SAQ D — you are not required to store, process, or transmit cardholder data, because you never receive it. Verify your specific integration against current PCI SSC guidance; this package removes the *need* to touch cardholder data, it doesn't retroactively certify your deployment.

## OWASP best practices applied

- **A01 Broken Access Control** — webhook routes carry no session/auth (providers can't authenticate as a user); protection is signature verification + rate limiting, not Laravel auth middleware, which would be the wrong tool here.
- **A02 Cryptographic Failures** — HMAC comparisons throughout use `hash_equals()` (constant-time), never `===`, closing timing-attack side channels.
- **A03 Injection** — all provider HTTP calls go through Laravel's `Http` client with parameterized request bodies; all Eloquent queries use the query builder, never raw string interpolation.
- **A04 Insecure Design** — idempotency, fraud hooks, and audit logging are architectural features, not afterthoughts bolted onto controllers.
- **A05 Security Misconfiguration** — `http.verify_ssl` defaults `true`; `InvalidConfigurationException` fails fast and loud rather than silently proceeding with missing credentials.
- **A08 Software and Data Integrity Failures** — webhook signature verification exists precisely to stop an attacker from injecting a fabricated "payment successful" event.
- **A09 Security Logging and Monitoring Failures** — `payment_logs`, `webhooks` (including failed-verification rows), and `audit_logs` together give a full forensic trail without needing to grep application log files.

## Secure secrets management

Nothing in this package requires secrets to live in version control. `.env` for single-tenant deployments; `provider_accounts.credentials` (encrypted) for multi-tenant; both patterns support injecting from an external secrets manager at deploy time instead. Never log a raw credential — `config('paysasa.logging.redact_fields')` (`pin`, `password`, `card_number`, `cvv`, `account_number`, `authorization_code`) is applied by `Support\PaymentLogger` to every context array before it's written, but that list is a safety net, not a substitute for not putting secrets in loggable structures in the first place.
