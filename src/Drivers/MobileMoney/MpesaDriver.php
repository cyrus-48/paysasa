<?php

declare(strict_types=1);

namespace Paysasa\Payments\Drivers\MobileMoney;

use Paysasa\Payments\Contracts\BalanceInquirable;
use Paysasa\Payments\Contracts\PayoutCapable;
use Paysasa\Payments\Contracts\Reversible;
use Paysasa\Payments\Contracts\WebhookHandler;
use Paysasa\Payments\DTOs\BalanceResponse;
use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\DTOs\WebhookPayload;
use Paysasa\Payments\Drivers\AbstractDriver;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Exceptions\InvalidConfigurationException;
use Paysasa\Payments\Services\Mpesa\DarajaAuthenticator;
use Paysasa\Payments\Services\Mpesa\DarajaClient;

/**
 * Safaricom Daraja driver. charge() drives Lipa Na M-Pesa Online (STK
 * Push) for the C2B collection flow used by the fluent API's ->phone()
 * example in the package README. Settlement is asynchronous: charge()
 * returns Pending immediately (Daraja only acknowledges the push was
 * sent), and the real outcome arrives via the STK callback webhook —
 * handleCallback() below — or, as a fallback, VerifyPaymentStatusJob
 * calling verify() through stkQuery.
 */
class MpesaDriver extends AbstractDriver implements BalanceInquirable, PayoutCapable, Reversible, WebhookHandler
{
    protected ?DarajaClient $client = null;

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Mpesa;
    }

    protected function client(): DarajaClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $this->requireConfig(['consumer_key', 'consumer_secret']);

        $auth = new DarajaAuthenticator($this->baseUrl(), $this->config['consumer_key'], $this->config['consumer_secret']);

        return $this->client = new DarajaClient($this->baseUrl(), $auth);
    }

    public function charge(ChargeRequest $request): PaymentResponse
    {
        $this->requireConfig(['shortcode', 'passkey']);

        if ($request->phone === null) {
            throw InvalidConfigurationException::missingKeys('mpesa', ['phone (charge request)']);
        }

        $this->log('info', 'Initiating STK push', ['amount' => $request->amount, 'reference' => $request->reference]);

        $result = $this->client()->stkPush([
            'shortcode' => $this->config['shortcode'],
            'passkey' => $this->config['passkey'],
            'amount' => $request->amount,
            'phone' => $this->normalizePhone($request->phone),
            'callback_url' => $request->callbackUrl ?? $this->config['stk_callback_url'],
            'reference' => $request->reference ?? 'Payment',
            'description' => $request->description,
        ]);

        // ResponseCode "0" means Safaricom accepted the push request, NOT that the customer paid.
        if (($result['ResponseCode'] ?? null) !== '0') {
            return PaymentResponse::makeFailed(
                $this->provider(),
                message: $result['errorMessage'] ?? $result['ResponseDescription'] ?? 'STK push was rejected',
                rawResponse: $result,
            );
        }

        return PaymentResponse::makePending(
            $this->provider(),
            transactionId: null,
            providerReference: $result['CheckoutRequestID'] ?? null,
            message: $result['CustomerMessage'] ?? 'STK push sent to customer',
            rawResponse: $result,
            metadata: ['merchant_request_id' => $result['MerchantRequestID'] ?? null],
        );
    }

    public function verify(string $providerReference): PaymentResponse
    {
        $this->requireConfig(['shortcode', 'passkey']);

        $result = $this->client()->stkQuery($this->config['shortcode'], $this->config['passkey'], $providerReference);

        $resultCode = $result['ResultCode'] ?? null;

        return match (true) {
            $resultCode === '0' || $resultCode === 0 => PaymentResponse::makeSuccessful(
                $this->provider(),
                transactionId: null,
                providerReference: $providerReference,
                message: $result['ResultDesc'] ?? null,
                rawResponse: $result,
            ),
            $resultCode === null => PaymentResponse::makePending($this->provider(), null, $providerReference, 'Awaiting customer action', $result),
            default => PaymentResponse::makeFailed($this->provider(), message: $result['ResultDesc'] ?? 'Transaction not successful', rawResponse: $result),
        };
    }

    public function payout(ChargeRequest $request): PaymentResponse
    {
        $this->requireConfig(['b2c_shortcode', 'initiator_name', 'b2c_queue_timeout_url', 'b2c_result_url']);

        $result = $this->client()->b2c([
            'initiator_name' => $this->config['initiator_name'],
            'security_credential' => $this->securityCredential(),
            'amount' => $request->amount,
            'shortcode' => $this->config['b2c_shortcode'],
            'phone' => $this->normalizePhone($request->phone),
            'remarks' => $request->description ?? 'Payout',
            'timeout_url' => $this->config['b2c_queue_timeout_url'],
            'result_url' => $this->config['b2c_result_url'],
            'occasion' => $request->reference,
        ]);

        if (($result['ResponseCode'] ?? null) !== '0') {
            return PaymentResponse::makeFailed($this->provider(), message: $result['ResponseDescription'] ?? 'B2C request rejected', rawResponse: $result);
        }

        return PaymentResponse::makePending(
            $this->provider(),
            transactionId: null,
            providerReference: $result['ConversationID'] ?? null,
            message: 'B2C payment accepted for processing',
            rawResponse: $result,
        );
    }

    public function reverse(string $providerReference, ?float $amount = null, ?string $reason = null): PaymentResponse
    {
        $this->requireConfig(['initiator_name', 'shortcode']);

        $result = $this->client()->reversal([
            'initiator_name' => $this->config['initiator_name'],
            'security_credential' => $this->securityCredential(),
            'transaction_id' => $providerReference,
            'amount' => $amount ?? 0,
            'shortcode' => $this->config['shortcode'],
            'result_url' => $this->config['b2c_result_url'] ?? $this->config['stk_callback_url'],
            'timeout_url' => $this->config['b2c_queue_timeout_url'] ?? $this->config['stk_callback_url'],
            'remarks' => $reason ?? 'Reversal',
        ]);

        if (($result['ResponseCode'] ?? null) !== '0') {
            return PaymentResponse::makeFailed($this->provider(), message: $result['ResponseDescription'] ?? 'Reversal rejected', rawResponse: $result);
        }

        return PaymentResponse::makePending($this->provider(), null, $result['ConversationID'] ?? null, 'Reversal accepted for processing', $result);
    }

    public function balance(): BalanceResponse
    {
        $this->requireConfig(['initiator_name', 'shortcode']);

        $result = $this->client()->accountBalance([
            'initiator_name' => $this->config['initiator_name'],
            'security_credential' => $this->securityCredential(),
            'shortcode' => $this->config['shortcode'],
            'timeout_url' => $this->config['b2c_queue_timeout_url'] ?? $this->config['stk_callback_url'],
            'result_url' => $this->config['b2c_result_url'] ?? $this->config['stk_callback_url'],
        ]);

        // Daraja returns the balance asynchronously via ResultURL; this call only confirms acceptance.
        return new BalanceResponse($this->provider(), available: 0.0, rawResponse: $result);
    }

    /**
     * Translates BOTH the STK callback (Body.stkCallback) and the C2B
     * confirmation callback (TransID/TransAmount at the payload root) into
     * a unified response — Daraja uses different shapes for each flow.
     */
    public function handleCallback(WebhookPayload $payload): PaymentResponse
    {
        if ($stk = $payload->get('Body.stkCallback')) {
            $resultCode = $stk['ResultCode'] ?? null;
            $items = collect($stk['CallbackMetadata']['Item'] ?? [])->pluck('Value', 'Name');

            if ((int) $resultCode === 0) {
                return PaymentResponse::makeSuccessful(
                    $this->provider(),
                    transactionId: null,
                    providerReference: $stk['CheckoutRequestID'] ?? null,
                    amount: isset($items['Amount']) ? (float) $items['Amount'] : null,
                    currency: 'KES',
                    message: $stk['ResultDesc'] ?? null,
                    receiptNumber: $items['MpesaReceiptNumber'] ?? null,
                    rawResponse: $payload->parsedBody,
                );
            }

            $status = (int) $resultCode === 1032 ? PaymentStatus::Cancelled : PaymentStatus::Failed;

            return new PaymentResponse(
                $this->provider(),
                $status,
                providerReference: $stk['CheckoutRequestID'] ?? null,
                message: $stk['ResultDesc'] ?? null,
                rawResponse: $payload->parsedBody,
            );
        }

        // C2B confirmation callback shape.
        return PaymentResponse::makeSuccessful(
            $this->provider(),
            transactionId: null,
            providerReference: $payload->get('TransID'),
            amount: $payload->get('TransAmount') !== null ? (float) $payload->get('TransAmount') : null,
            currency: 'KES',
            receiptNumber: $payload->get('TransID'),
            rawResponse: $payload->parsedBody,
        );
    }

    protected function securityCredential(): string
    {
        if (! empty($this->config['security_credential'])) {
            return $this->config['security_credential'];
        }

        $this->requireConfig(['certificate_path', 'initiator_password']);

        $certificate = file_get_contents($this->config['certificate_path']);
        openssl_public_encrypt($this->config['initiator_password'], $encrypted, $certificate, OPENSSL_PKCS1_PADDING);

        return base64_encode($encrypted);
    }

    protected function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if (str_starts_with($digits, '0')) {
            return '254'.substr($digits, 1);
        }

        if (str_starts_with($digits, '7') || str_starts_with($digits, '1')) {
            return '254'.$digits;
        }

        return $digits;
    }
}
