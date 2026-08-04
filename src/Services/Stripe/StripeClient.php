<?php

declare(strict_types=1);

namespace Paysasa\Payments\Services\Stripe;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Paysasa\Payments\Exceptions\ProviderApiException;

/**
 * Minimal raw-HTTP Stripe client covering exactly what StripeDriver needs
 * (PaymentIntents, Refunds). Deliberately avoids a hard dependency on
 * stripe/stripe-php — install it yourself (see composer.json `suggest`)
 * and swap this out if you need the full SDK surface (Connect, Billing,
 * Radar, etc.).
 */
class StripeClient
{
    public function __construct(
        protected string $secretKey,
        protected string $apiVersion,
    ) {
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl('https://api.stripe.com/v1')
            ->withToken($this->secretKey)
            ->withHeaders(['Stripe-Version' => $this->apiVersion])
            ->asForm()
            ->timeout(30);
    }

    public function createPaymentIntent(array $params): array
    {
        return $this->decode($this->http()->post('/payment_intents', $params), 'create PaymentIntent');
    }

    public function capturePaymentIntent(string $id, ?int $amountMinor = null): array
    {
        $params = $amountMinor !== null ? ['amount_to_capture' => $amountMinor] : [];

        return $this->decode($this->http()->post("/payment_intents/{$id}/capture", $params), 'capture PaymentIntent');
    }

    public function cancelPaymentIntent(string $id): array
    {
        return $this->decode($this->http()->post("/payment_intents/{$id}/cancel"), 'cancel PaymentIntent');
    }

    public function retrievePaymentIntent(string $id): array
    {
        return $this->decode($this->http()->get("/payment_intents/{$id}"), 'retrieve PaymentIntent');
    }

    public function createRefund(array $params): array
    {
        return $this->decode($this->http()->post('/refunds', $params), 'create refund');
    }

    protected function decode(Response $response, string $context): array
    {
        if ($response->failed()) {
            throw new ProviderApiException(
                'Stripe API error during '.$context.': '.($response->json('error.message') ?? "HTTP {$response->status()}"),
                $response->status(),
                $response->json(),
            );
        }

        return $response->json() ?? [];
    }
}
