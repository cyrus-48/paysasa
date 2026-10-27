<?php

declare(strict_types=1);

namespace Paysasa\Payments\Drivers\MobileMoney;

use Paysasa\Payments\Contracts\BalanceInquirable;
use Paysasa\Payments\Contracts\ConfirmsCallbacks;
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
use Paysasa\Payments\Exceptions\ProviderApiException;
use Paysasa\Payments\Exceptions\UnsupportedOperationException;
use Paysasa\Payments\Services\Mpesa\DarajaAuthenticator;
use Paysasa\Payments\Services\Mpesa\DarajaClient;
use Paysasa\Payments\Services\Mpesa\DarajaResultMap;
use Paysasa\Payments\ValueObjects\KenyanMsisdn;
use Paysasa\Payments\ValueObjects\Money;

/**
 * Safaricom Daraja driver. charge() drives Lipa Na M-Pesa Online (STK
 * Push) for the C2B collection flow used by the fluent API's ->phone()
 * example in the package README. Settlement is asynchronous: charge()
 * returns Pending immediately (Daraja only acknowledges the push was
 * sent), and the real outcome arrives via the STK callback webhook —
 * handleCallback() below — or, as a fallback, VerifyPaymentStatusJob
 * calling verify() through stkQuery.
 */
class MpesaDriver extends AbstractDriver implements BalanceInquirable, ConfirmsCallbacks, PayoutCapable, Reversible, WebhookHandler
{
    protected ?DarajaClient $client = null;

    protected ?string $cachedSecurityCredential = null;

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

        $this->log('info', 'Initiating STK push', ['amount' => $request->amountMajor(), 'reference' => $request->reference]);

        $result = $this->client()->stkPush([
            'shortcode' => $this->config['shortcode'],
            'passkey' => $this->config['passkey'],
            'amount' => $request->amount->wholeUnits(),
            'phone' => $this->normalizePhone($request->phone),
            'callback_url' => $this->resolveCallbackUrl($request->callbackUrl),
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
        if (! str_starts_with($providerReference, 'ws_CO_') && ! empty($this->config['initiator_name'])) {
            $this->requireConfig(['initiator_name', 'shortcode']);
            $result = $this->client()->transactionStatus([
                'initiator_name' => $this->config['initiator_name'],
                'security_credential' => $this->securityCredential(),
                'transaction_id' => $providerReference,
                'shortcode' => $this->config['shortcode'],
                'result_url' => $this->config['b2c_result_url'] ?? $this->config['stk_callback_url'],
                'timeout_url' => $this->config['b2c_queue_timeout_url'] ?? $this->config['stk_callback_url'],
            ]);

            if (($result['ResponseCode'] ?? null) !== '0') {
                return PaymentResponse::makeFailed(
                    $this->provider(),
                    message: $result['ResponseDescription'] ?? 'Transaction status query rejected',
                    rawResponse: $result,
                );
            }

            return PaymentResponse::makePending(
                $this->provider(),
                null,
                $providerReference,
                'Transaction status query submitted',
                $result
            );
        }

        $this->requireConfig(['shortcode', 'passkey']);

        try {
            $result = $this->client()->stkQuery($this->config['shortcode'], $this->config['passkey'], $providerReference);
        } catch (ProviderApiException $e) {
            $raw = $e->rawResponse();
            $message = is_array($raw) ? ($raw['errorMessage'] ?? $raw['ResponseDescription'] ?? '') : (string) $raw;
            if (str_contains(strtolower((string) $message), 'being processed') || str_contains(strtolower($e->getMessage()), 'being processed')) {
                return PaymentResponse::makePending(
                    $this->provider(),
                    null,
                    $providerReference,
                    'Transaction is being processed',
                    is_array($raw) ? $raw : []
                );
            }

            throw $e;
        }

        $resultCode = $result['ResultCode'] ?? null;
        $status = DarajaResultMap::map($resultCode);

        return match ($status) {
            PaymentStatus::Successful => PaymentResponse::makeSuccessful(
                $this->provider(),
                transactionId: null,
                providerReference: $providerReference,
                message: $result['ResultDesc'] ?? null,
                rawResponse: $result,
                metadata: ['result_code' => $resultCode],
            ),
            PaymentStatus::Cancelled => PaymentResponse::makeCancelled(
                $this->provider(),
                transactionId: null,
                message: $result['ResultDesc'] ?? 'Transaction was cancelled by customer',
                rawResponse: $result,
            ),
            PaymentStatus::Expired => new PaymentResponse(
                $this->provider(),
                PaymentStatus::Expired,
                transactionId: null,
                providerReference: $providerReference,
                message: $result['ResultDesc'] ?? 'Transaction expired',
                rawResponse: $result,
                metadata: ['result_code' => $resultCode],
            ),
            PaymentStatus::Pending => PaymentResponse::makePending(
                $this->provider(),
                null,
                $providerReference,
                $result['ResultDesc'] ?? 'Awaiting customer action',
                $result
            ),
            default => PaymentResponse::makeFailed(
                $this->provider(),
                message: $result['ResultDesc'] ?? 'Transaction not successful',
                rawResponse: $result,
                metadata: [
                    'result_code' => $resultCode,
                    'failure_code' => DarajaResultMap::failureCode($resultCode),
                ],
            ),
        };
    }

    public function payout(ChargeRequest $request): PaymentResponse
    {
        $this->requireConfig(['b2c_shortcode', 'initiator_name', 'b2c_queue_timeout_url', 'b2c_result_url']);

        $result = $this->client()->b2c([
            'initiator_name' => $this->config['initiator_name'],
            'security_credential' => $this->securityCredential(),
            'amount' => $request->amount->wholeUnits(),
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

        if ($amount === null || $amount <= 0) {
            throw new \InvalidArgumentException('M-Pesa reversal requires an explicit positive amount.');
        }

        $money = Money::of($amount, \Paysasa\Payments\Enums\Currency::KES);
        $amountUnits = $money->wholeUnits();

        $result = $this->client()->reversal([
            'initiator_name' => $this->config['initiator_name'],
            'security_credential' => $this->securityCredential(),
            'transaction_id' => $providerReference,
            'amount' => $amountUnits,
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
        throw UnsupportedOperationException::make(
            'mpesa',
            'synchronous balance inquiry (Daraja delivers balance asynchronously via result URL)'
        );
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
            $status = DarajaResultMap::map($resultCode);

            if ($status === PaymentStatus::Successful) {
                return PaymentResponse::makeSuccessful(
                    $this->provider(),
                    transactionId: null,
                    providerReference: $stk['CheckoutRequestID'] ?? null,
                    amount: isset($items['Amount']) ? (float) $items['Amount'] : null,
                    currency: 'KES',
                    message: $stk['ResultDesc'] ?? null,
                    receiptNumber: $items['MpesaReceiptNumber'] ?? null,
                    rawResponse: $payload->parsedBody,
                    metadata: ['result_code' => $resultCode],
                );
            }

            if ($status === PaymentStatus::Cancelled) {
                return PaymentResponse::makeCancelled(
                    $this->provider(),
                    transactionId: null,
                    message: $stk['ResultDesc'] ?? null,
                    rawResponse: $payload->parsedBody,
                );
            }

            return new PaymentResponse(
                $this->provider(),
                $status,
                providerReference: $stk['CheckoutRequestID'] ?? null,
                message: $stk['ResultDesc'] ?? null,
                rawResponse: $payload->parsedBody,
                metadata: [
                    'result_code' => $resultCode,
                    'failure_code' => DarajaResultMap::failureCode($resultCode),
                ],
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

    /**
     * Daraja does not sign callbacks, so an STK "success" is confirmed with an
     * STK query before it settles a payment (`confirm_callbacks`, on by default).
     * Only STK callbacks (CheckoutRequestID `ws_CO_...`) can be queried this
     * way; C2B confirmations carry a TransID instead.
     */
    public function shouldConfirmCallback(PaymentResponse $callback): bool
    {
        if (! (bool) ($this->config['confirm_callbacks'] ?? true)) {
            return false;
        }

        return $callback->status === PaymentStatus::Successful
            && is_string($callback->providerReference)
            && str_starts_with($callback->providerReference, 'ws_CO_');
    }

    protected function securityCredential(): string
    {
        if ($this->cachedSecurityCredential !== null) {
            return $this->cachedSecurityCredential;
        }

        if (! empty($this->config['security_credential'])) {
            return $this->cachedSecurityCredential = (string) $this->config['security_credential'];
        }

        $this->requireConfig(['certificate_path', 'initiator_password']);

        $path = (string) $this->config['certificate_path'];
        if (! file_exists($path) || ! is_readable($path)) {
            throw InvalidConfigurationException::invalidValue('mpesa', "Certificate file at [{$path}] does not exist or is not readable.");
        }

        $certificate = @file_get_contents($path);
        if ($certificate === false) {
            throw InvalidConfigurationException::invalidValue('mpesa', "Failed to read certificate at [{$path}].");
        }

        $encrypted = '';
        $success = openssl_public_encrypt((string) $this->config['initiator_password'], $encrypted, $certificate, OPENSSL_PKCS1_PADDING);
        if (! $success) {
            throw InvalidConfigurationException::invalidValue('mpesa', 'Failed to encrypt initiator password with certificate. Verify certificate format.');
        }

        return $this->cachedSecurityCredential = base64_encode($encrypted);
    }

    protected function resolveCallbackUrl(?string $overrideUrl): string
    {
        $default = (string) ($this->config['stk_callback_url'] ?? '');

        if ($overrideUrl === null || $overrideUrl === '') {
            return $default;
        }

        $allowedHosts = (array) ($this->config['allowed_callback_hosts'] ?? []);
        if (! empty($allowedHosts)) {
            $parsedHost = parse_url($overrideUrl, PHP_URL_HOST);
            if (! in_array($parsedHost, $allowedHosts, true)) {
                throw InvalidConfigurationException::invalidValue('mpesa', "Callback URL host [{$parsedHost}] is not permitted by allowed_callback_hosts.");
            }
        }

        return $overrideUrl;
    }

    protected function normalizePhone(?string $phone): string
    {
        if ($phone === null) {
            return '';
        }

        return KenyanMsisdn::of($phone)->toString();
    }
}
