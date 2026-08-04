# Performance Optimization & Scalability

## Performance

- **OAuth tokens are cached, not re-fetched per request.** Daraja/Airtel/Pesapal tokens are cached for just under their real TTL — under load, this is the difference between one token request per ~50 minutes and one per API call.
- **Webhook processing is off the request cycle.** `WebhookController` does the minimum work needed to verify and persist before responding `200` — the actual status update and side effects happen in `DispatchWebhookJob` on a queue worker, so a slow database write or a slow listener never risks the provider timing out and disabling your callback URL.
- **HTTP retries are bounded and configurable**, not unbounded — `config('paysasa.http.retry')` (default 3 attempts, 500ms initial sleep) prevents a flaky provider from turning one slow request into a request that hangs indefinitely.
- **Idempotency lookups hit the cache, not the database**, for the common case of a genuine duplicate submission within the TTL window — no query against `payments` is needed to short-circuit a repeat.
- **JSON columns over EAV.** `metadata`, `payload`, `settings` are native JSON columns (indexed where the database supports it) rather than a key-value side table — avoids N+1-style joins for what is fundamentally document-shaped data.

## Scalability considerations

- **Stateless drivers.** No driver instance holds request-specific mutable state between calls (the `?StripeClient $client = null` lazy-init pattern is safe because it's per-instance, and `PaymentManager` caches driver instances per `(name, merchantId)` pair, not globally) — safe to run behind a horizontally-scaled fleet of app servers with no sticky-session requirement.
- **Idempotency and OAuth token caching must use a shared cache store** (Redis, Memcached) in a multi-server deployment — `array`/`file` cache stores work for local dev only, since they're not shared across servers. `config('paysasa.idempotency.store')` and the queue-backed jobs both assume this.
- **Queue-based webhook processing scales horizontally** by adding queue workers — `config('paysasa.queue.webhooks_queue')` is a dedicated queue name so you can scale webhook-processing workers independently of general application job workers (e.g. more workers during a promotional period with high STK-push volume).
- **Multi-merchant credential resolution is a single indexed query** (`provider_accounts` keyed on `merchant_id, provider, is_active`) per driver resolution, cached for the lifetime of the request via `PaymentManager`'s per-request driver instance cache — not re-queried on every fluent-builder method call.
- **Database growth**: `payment_logs` and `webhooks` grow unboundedly with transaction volume. Neither table is queried in the hot charge path (they're written to, not read from, during a charge), so growth affects storage cost and maintenance-window backup time, not charge latency — plan a retention/archival policy (e.g. move `payment_logs` older than 90 days to cold storage) appropriate to your compliance requirements; the package doesn't prescribe one since retention requirements vary by regulator and business.
- **Read replicas**: `EloquentPaymentRepository` uses standard Eloquent queries with no query hints forcing a specific connection — works transparently with Laravel's read/write connection splitting if you configure it, since writes (charge, webhook processing) and reads (status lookups, reporting) naturally separate.
