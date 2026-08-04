<?php

declare(strict_types=1);

namespace Paysasa\Payments\Drivers\MobileMoney;

use Paysasa\Payments\Contracts\WebhookHandler;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Drivers\AbstractDriver;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Exceptions\InvalidConfigurationException;
use Ramsey\Uuid\Uuid;

/**
 * T-Kash (Telkom Kenya) driver.
 *
 * NOTE: unlike Daraja/Airtel/Stripe, Telkom does not publish a single
 * stable, versioned public API specification for T-Kash — merchant
 * integration is arranged bilaterally with Telkom and the exact endpoint
 * paths/payload field names vary by integration agreement. This driver
 * implements the widely-documented shape (API-key authenticated REST push
 * request + status query) so it's wired end-to-end through
 * Paysasa\Payments\Contracts\PaymentDriver; when you receive your Telkom
 * integration pack, adjust the endpoint paths and field names in
 * `charge()` / `verify()` / `handleCallback()` to match it exactly. The
 * request/response mapping to PaymentResponse and every other layer of
 * the package (events, persistence, webhooks) needs no changes.
 */
class TKashDriver extends AbstractDriver implements WebhookHandler
{
    public function provider(): PaymentProvider
    {
        return PaymentProvider::TKash;
    }

    public function charge(ChargeRequest $request): PaymentResponse
    {
        $this->requireConfig(['api_key', 'merchant_code']);

        if ($request->phone === null) {
            throw InvalidConfigurationException::missingKeys('tkash', ['phone (charge request)']);
        }

        $transactionId = Uuid::uuid4()->toString();

        $response = $this->http()
            ->withToken($this->config['api_key'])
            ->post('/v1/push', [
                'merchant_code' => $this->config['merchant_code'],
                'msisdn' => $this->normalizePhone($request->phone),
                'amount' => $request->amount,
                'reference' => $request->reference ?? $transactionId,
                'callback_url' => $request->callbackUrl ?? $this->config['callback_url'],
            ]);

        $this->throwIfFailed($response, 'push request');
        $result = $response->json() ?? [];

        if (($result['status'] ?? null) !== 'PENDING' && ($result['status'] ?? null) !== 'ACCEPTED') {
            return PaymentResponse::makeFailed($this->provider(), message: $result['message'] ?? 'Push request rejected', rawResponse: $result);
        }

        return PaymentResponse::makePending($this->provider(), null, $result['transaction_id'] ?? $transactionId, 'Push sent to customer', $result);
    }

    public function verify(string $providerReference): PaymentResponse
    {
        $response = $this->http()->withToken($this->config['api_key'])->get("/v1/status/{$providerReference}");
        $this->throwIfFailed($response, 'status query');
        $result = $response->json() ?? [];

        return match ($result['status'] ?? null) {
            'SUCCESS', 'COMPLETED' => PaymentResponse::makeSuccessful($this->provider(), null, $providerReference, receiptNumber: $result['receipt'] ?? null, rawResponse: $result),
            'FAILED' => PaymentResponse::makeFailed($this->provider(), message: $result['message'] ?? 'Transaction failed', rawResponse: $result),
            default => PaymentResponse::makePending($this->provider(), null, $providerReference, rawResponse: $result),
        };
    }

    public function handleCallback(WebhookPayload $payload): PaymentResponse
    {
        return match ($payload->get('status')) {
            'SUCCESS', 'COMPLETED' => PaymentResponse::makeSuccessful(
                $this->provider(),
                null,
                $payload->get('transaction_id'),
                amount: $payload->get('amount') !== null ? (float) $payload->get('amount') : null,
                receiptNumber: $payload->get('receipt'),
                rawResponse: $payload->parsedBody,
            ),
            default => PaymentResponse::makeFailed($this->provider(), message: 'Transaction failed', rawResponse: $payload->parsedBody),
        };
    }

    protected function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return str_starts_with($digits, '0') ? '254'.substr($digits, 1) : $digits;
    }
}
