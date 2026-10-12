<?php

declare(strict_types=1);

namespace Paysasa\Payments\Http\Controllers;

use Illuminate\Database\UniqueConstraintViolationException;
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
 * Single entry point for every provider's inbound webhook/IPN/callback.
 */
class WebhookController extends Controller
{
    public function __construct(protected WebhookDispatcher $dispatcher)
    {
    }

    public function handle(Request $request, string $provider): JsonResponse
    {
        $maxBodyBytes = (int) config('paysasa.webhooks.max_body_bytes', 65536);
        $rawBody = $request->getContent();

        if (strlen($rawBody) > $maxBodyBytes) {
            return response()->json(['message' => 'Payload too large'], 413);
        }

        $aliases = config('paysasa.webhook_aliases', []);
        $isOpaqueRoute = array_key_exists($provider, $aliases);
        $resolvedProvider = $aliases[$provider] ?? $provider;

        $providerEnum = PaymentProvider::tryFrom($resolvedProvider);

        if ($providerEnum === null) {
            return response()->json(['message' => 'Unknown provider'], 404);
        }

        // Redact sensitive headers
        $sensitive = ['authorization', 'cookie', 'set-cookie', 'php-auth-user', 'php-auth-pw', 'x-csrf-token'];
        $filteredHeaders = array_filter(
            $request->headers->all(),
            fn ($key) => ! in_array(strtolower($key), $sensitive, true),
            ARRAY_FILTER_USE_KEY
        );

        $parsedBody = $request->all();
        $payload = new WebhookPayload($providerEnum, $filteredHeaders, $rawBody, $parsedBody);

        $webhook = Webhook::create([
            'provider' => $resolvedProvider,
            'status' => WebhookStatus::Received,
            'headers' => $filteredHeaders,
            'payload' => $parsedBody,
            'ip_address' => $request->ip(),
        ]);

        try {
            $this->dispatcher->verifierFor($resolvedProvider, $isOpaqueRoute)->verify($payload);
        } catch (WebhookVerificationException $e) {
            $webhook->update([
                'status' => WebhookStatus::VerificationFailed,
                'signature_valid' => false,
                'error_message' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Signature verification failed'], 401);
        }

        // De-duplication key
        $providerEventId = $request->input('event_id') ?? $request->input('id');
        $dedupeKey = is_string($providerEventId) && $providerEventId !== ''
            ? $providerEventId
            : hash('sha256', $resolvedProvider . $rawBody);

        $existing = Webhook::query()
            ->where('provider', $resolvedProvider)
            ->where('dedupe_key', $dedupeKey)
            ->where('id', '!=', $webhook->id)
            ->first();

        if ($existing !== null) {
            $webhook->update(['status' => WebhookStatus::Ignored]);

            return response()->json(['message' => 'Duplicate webhook ignored'], 200);
        }

        try {
            $webhook->update([
                'dedupe_key' => $dedupeKey,
                'provider_event_id' => is_string($providerEventId) ? $providerEventId : null,
                'signature_valid' => true,
                'verified_at' => now(),
                'status' => WebhookStatus::Received,
            ]);
        } catch (UniqueConstraintViolationException) {
            $webhook->update(['status' => WebhookStatus::Ignored]);

            return response()->json(['message' => 'Duplicate webhook ignored'], 200);
        }

        event(new WebhookReceived($webhook, $payload));

        DispatchWebhookJob::dispatch($webhook, $filteredHeaders, $rawBody);

        return response()->json(['message' => 'Webhook accepted'], 200);
    }
}
