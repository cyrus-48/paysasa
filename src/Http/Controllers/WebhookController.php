<?php

declare(strict_types=1);

namespace Paysasa\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\WebhookStatus;
use Paysasa\Payments\Events\WebhookReceived;
use Paysasa\Payments\Exceptions\WebhookVerificationException;
use Paysasa\Payments\Jobs\DispatchWebhookJob;
use Paysasa\Payments\Models\Webhook;
use Paysasa\Payments\Webhooks\WebhookDispatcher;

/**
 * Single entry point for every provider's inbound webhook/IPN/callback:
 * paysasa/webhooks/{provider}. The lifecycle here is deliberately uniform
 * across all 13 providers even though each one's payload shape differs —
 * see Documentation/06-webhook-guide.md for the full sequence diagram:
 *
 *   1. Persist the raw payload immediately (before verification), so a
 *      spoofed/failed request is still forensically recoverable.
 *   2. Verify the signature via the provider's Webhooks\Verifiers\* strategy.
 *   3. Deduplicate (replay-attack / provider-retry protection).
 *   4. Fire WebhookReceived, hand off to DispatchWebhookJob, ack fast.
 */
class WebhookController extends Controller
{
    public function __construct(protected WebhookDispatcher $dispatcher)
    {
    }

    public function handle(Request $request, string $provider): JsonResponse
    {
        $providerEnum = PaymentProvider::tryFrom($provider);

        if ($providerEnum === null) {
            return response()->json(['message' => 'Unknown provider'], 404);
        }

        $rawBody = $request->getContent();
        $parsedBody = $request->all();
        $headers = $request->headers->all();

        $payload = new WebhookPayload($providerEnum, $headers, $rawBody, $parsedBody);

        $webhook = Webhook::create([
            'provider' => $provider,
            'status' => WebhookStatus::Received,
            'headers' => $headers,
            'payload' => $parsedBody,
            'ip_address' => $request->ip(),
        ]);

        try {
            $this->dispatcher->verifierFor($provider)->verify($payload);
        } catch (WebhookVerificationException $e) {
            $webhook->update(['status' => WebhookStatus::VerificationFailed, 'signature_valid' => false, 'error_message' => $e->getMessage()]);

            return response()->json(['message' => 'Signature verification failed'], 401);
        }

        $webhook->update(['signature_valid' => true, 'verified_at' => now()]);

        if ($this->isDuplicate($webhook)) {
            $webhook->update(['status' => WebhookStatus::Ignored]);

            return response()->json(['message' => 'Duplicate webhook ignored'], 200);
        }

        event(new WebhookReceived($webhook, $payload));

        DispatchWebhookJob::dispatch($webhook, $headers, $rawBody);

        return response()->json(['message' => 'Webhook accepted'], 200);
    }

    /**
     * A provider retrying an unacknowledged webhook (or an attacker
     * replaying a captured one) produces an identical payload hash for the
     * same provider within a short window — treat it as already handled
     * rather than reprocessing and double-crediting a payment.
     */
    protected function isDuplicate(Webhook $webhook): bool
    {
        $fingerprint = hash('sha256', json_encode($webhook->payload));

        return Webhook::query()
            ->where('provider', $webhook->provider)
            ->where('id', '!=', $webhook->id)
            ->where('status', WebhookStatus::Processed->value)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->get(['payload'])
            ->contains(fn ($w) => hash('sha256', json_encode($w->payload)) === $fingerprint);
    }
}
