<?php

declare(strict_types=1);

namespace Paysasa\Payments\Drivers\MobileMoney;

use Paysasa\Payments\Contracts\ConfirmsCallbacks;
use Paysasa\Payments\Contracts\PayoutCapable;
use Paysasa\Payments\Contracts\Refundable;
use Paysasa\Payments\Contracts\WebhookHandler;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\DTOs\RefundRequest;
use Paysasa\Payments\DTOs\RefundResponse;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Drivers\AbstractDriver;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Enums\RefundStatus;
use Paysasa\Payments\Exceptions\InvalidConfigurationException;
use Paysasa\Payments\Exceptions\UnsupportedOperationException;
use Paysasa\Payments\Services\Airtel\AirtelAuthenticator;
use Paysasa\Payments\Services\Airtel\AirtelClient;
use Paysasa\Payments\ValueObjects\KenyanMsisdn;
use Ramsey\Uuid\Uuid;

/**
 * Airtel Money OpenAPI driver (collections via USSD push, enquiry, refunds,
 * disbursements).
 *
 * Settlement is asynchronous: charge() returns Pending once Airtel accepts the
 * push, and the outcome arrives by callback (handleCallback()) or, as a
 * fallback, VerifyPaymentStatusJob calling verify() (transaction enquiry).
 *
 * Airtel callbacks carry no signature we can verify, so a claimed success is
 * confirmed with a transaction enquiry before it settles a payment
 * (ConfirmsCallbacks) — a forged callback can never mark a payment as paid.
 *
 * Two ids matter: the merchant transaction id we generate (`transaction.id`,
 * stored as the payment's provider_reference and used for enquiry and for
 * matching callbacks) and Airtel's own `airtel_money_id` (stored as the
 * receipt number), which refunds require.
 */
class AirtelMoneyDriver extends AbstractDriver implements ConfirmsCallbacks, PayoutCapable, Refundable, WebhookHandler
{
    protected ?AirtelClient $client = null;

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Airtel;
    }

    protected function client(): AirtelClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $this->requireConfig(['client_id', 'client_secret']);

        $auth = new AirtelAuthenticator($this->baseUrl(), $this->config['client_id'], $this->config['client_secret']);

        return $this->client = new AirtelClient($this->baseUrl(), $auth, $this->configuredCountry(), $this->configuredCurrency());
    }

    public function charge(ChargeRequest $request): PaymentResponse
    {
        if ($request->phone === null) {
            throw InvalidConfigurationException::missingKeys('airtel', ['phone (charge request)']);
        }

        $this->assertCurrency($request->currency->value);

        $transactionId = Uuid::uuid4()->toString();

        $result = $this->client()->collect([
            'reference' => $request->reference ?? $transactionId,
            'phone' => $this->nationalNumber($request->phone),
            'amount' => $request->amountMajor(),
            'transaction_id' => $transactionId,
        ]);

        if (! ($result['status']['success'] ?? false)) {
            return PaymentResponse::makeFailed($this->provider(), message: $result['status']['message'] ?? 'Collection request rejected', rawResponse: $result);
        }

        return PaymentResponse::makePending(
            $this->provider(),
            transactionId: null,
            providerReference: $result['data']['transaction']['id'] ?? $transactionId,
            message: 'USSD push sent to customer',
            rawResponse: $result,
        );
    }

    public function verify(string $providerReference): PaymentResponse
    {
        $result = $this->client()->transactionEnquiry($providerReference);
        $transaction = $result['data']['transaction'] ?? [];

        return $this->responseFromTransaction(
            $this->mapStatus($transaction['status'] ?? null),
            $providerReference,
            $transaction,
            $result,
        );
    }

    public function payout(ChargeRequest $request): PaymentResponse
    {
        if ($request->phone === null) {
            throw InvalidConfigurationException::missingKeys('airtel', ['phone (payout request)']);
        }

        $this->assertCurrency($request->currency->value);

        // Never send a disbursement with a missing PIN (Airtel would just reject it).
        $this->requireConfig(['disbursement_pin', 'public_key']);

        $transactionId = Uuid::uuid4()->toString();

        $result = $this->client()->disburse([
            'phone' => $this->nationalNumber($request->phone),
            'reference' => $request->reference ?? $transactionId,
            'amount' => $request->amountMajor(),
            'transaction_id' => $transactionId,
            'encrypted_pin' => $this->encryptedPin(),
        ]);

        return ($result['status']['success'] ?? false)
            ? PaymentResponse::makePending($this->provider(), null, $result['data']['transaction']['id'] ?? $transactionId, 'Disbursement accepted', $result)
            : PaymentResponse::makeFailed($this->provider(), message: $result['status']['message'] ?? 'Disbursement rejected', rawResponse: $result);
    }

    /**
     * Airtel's refund API takes only the original transaction's `airtel_money_id`
     * (our receipt number) — no amount — so it always refunds the full payment.
     * ProcessRefund supplies `receipt_number` and `payment_amount` in the
     * request metadata.
     */
    public function refund(RefundRequest $request): RefundResponse
    {
        $airtelMoneyId = $request->metadata['receipt_number'] ?? null;

        if (! is_string($airtelMoneyId) || $airtelMoneyId === '') {
            throw UnsupportedOperationException::make('airtel', 'refund of a payment with no Airtel Money ID (receipt number) — wait until the payment is confirmed');
        }

        $paymentAmount = $request->metadata['payment_amount'] ?? null;

        if ($request->amount !== null && (! is_numeric($paymentAmount) || abs($request->amount - (float) $paymentAmount) > 0.00001)) {
            throw UnsupportedOperationException::make('airtel', 'partial refund (Airtel refunds the full transaction only)');
        }

        $result = $this->client()->refund($airtelMoneyId);
        $success = $result['status']['success'] ?? false;

        return new RefundResponse(
            $this->provider(),
            $success ? RefundStatus::Completed : RefundStatus::Failed,
            refundId: $result['data']['transaction']['id'] ?? $result['data']['transaction']['airtel_money_id'] ?? null,
            providerReference: $request->providerReference,
            amount: $request->amount,
            message: $result['status']['message'] ?? null,
            rawResponse: $result,
        );
    }

    /**
     * Callback body: `{"transaction": {"id": <our transaction id>, "status_code": "TS",
     * "airtel_money_id": "...", "message": "..."}}`. The enquiry API spells the
     * status `status`, so both keys are accepted. Only TS is success, TF is
     * failure and TE expiry; anything else (TIP in progress, TA ambiguous) is
     * still pending — it must not be recorded as a failure.
     */
    public function handleCallback(WebhookPayload $payload): PaymentResponse
    {
        $transaction = $payload->get('transaction', []);
        $transaction = is_array($transaction) ? $transaction : [];

        $reference = $transaction['id'] ?? $transaction['airtel_money_id'] ?? null;

        return $this->responseFromTransaction(
            $this->mapStatus($transaction['status_code'] ?? $transaction['status'] ?? null),
            $reference !== null ? (string) $reference : null,
            $transaction,
            $payload->parsedBody,
        );
    }

    /** Airtel callbacks are unsigned, so a claimed success is checked with the enquiry API before it settles anything. */
    public function shouldConfirmCallback(PaymentResponse $callback): bool
    {
        if (! (bool) ($this->config['confirm_callbacks'] ?? true)) {
            return false;
        }

        return $callback->status === PaymentStatus::Successful
            && is_string($callback->providerReference)
            && $callback->providerReference !== '';
    }

    private function mapStatus(mixed $code): PaymentStatus
    {
        return match (is_string($code) ? strtoupper($code) : null) {
            'TS' => PaymentStatus::Successful,
            'TF' => PaymentStatus::Failed,
            'TE' => PaymentStatus::Expired,
            default => PaymentStatus::Pending, // TIP (in progress), TA (ambiguous), unknown
        };
    }

    /**
     * @param array<string, mixed> $transaction the `transaction` object from an enquiry response or a callback
     */
    private function responseFromTransaction(PaymentStatus $status, ?string $providerReference, array $transaction, mixed $raw): PaymentResponse
    {
        $message = isset($transaction['message']) ? (string) $transaction['message'] : null;
        $airtelMoneyId = isset($transaction['airtel_money_id']) ? (string) $transaction['airtel_money_id'] : null;
        $amount = isset($transaction['amount']) && is_numeric($transaction['amount']) ? (float) $transaction['amount'] : null;

        if ($status === PaymentStatus::Successful) {
            return PaymentResponse::makeSuccessful(
                $this->provider(),
                transactionId: null,
                providerReference: $providerReference,
                amount: $amount,
                message: $message,
                receiptNumber: $airtelMoneyId,
                rawResponse: $raw,
            );
        }

        return new PaymentResponse(
            $this->provider(),
            $status,
            transactionId: null,
            providerReference: $providerReference,
            message: $message ?? match ($status) {
                PaymentStatus::Failed => 'Transaction failed',
                PaymentStatus::Expired => 'Transaction expired',
                default => 'Awaiting confirmation',
            },
            rawResponse: $raw,
        );
    }

    private function configuredCountry(): string
    {
        return (string) ($this->config['country'] ?? 'KE');
    }

    private function configuredCurrency(): string
    {
        return strtoupper((string) ($this->config['currency'] ?? 'KES'));
    }

    /**
     * Airtel debits in the currency this driver is configured for (the
     * X-Currency header); silently charging a USD request in KES would be a
     * money bug, so refuse a mismatch.
     */
    private function assertCurrency(string $requested): void
    {
        $configured = $this->configuredCurrency();

        if ($requested !== $configured) {
            throw InvalidConfigurationException::invalidValue(
                'airtel',
                "Request currency [{$requested}] does not match the configured Airtel currency [{$configured}] (AIRTEL_CURRENCY)."
            );
        }
    }

    /**
     * Airtel wants the subscriber number without the country code
     * (e.g. 712345678 for +254 712 345 678).
     */
    private function nationalNumber(string $phone): string
    {
        return substr(KenyanMsisdn::of($phone)->toString(), 3);
    }

    /** RSA (PKCS#1 v1.5) encrypt the disbursement PIN with Airtel's public key and base64 it. */
    private function encryptedPin(): string
    {
        $key = trim(str_replace('\n', "\n", (string) $this->config['public_key']));

        // Airtel hands out the key as bare base64; openssl needs PEM armour.
        if (! str_contains($key, 'BEGIN')) {
            $key = "-----BEGIN PUBLIC KEY-----\n".chunk_split(preg_replace('/\s+/', '', $key) ?? '', 64, "\n")."-----END PUBLIC KEY-----";
        }

        $encrypted = '';

        if (! @openssl_public_encrypt((string) $this->config['disbursement_pin'], $encrypted, $key, OPENSSL_PKCS1_PADDING)) {
            throw InvalidConfigurationException::invalidValue('airtel', 'Failed to encrypt the disbursement PIN: check that AIRTEL_PUBLIC_KEY is a valid RSA public key.');
        }

        return base64_encode($encrypted);
    }
}
