<?php

declare(strict_types=1);

namespace Paysasa\Payments\Services\Mpesa;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Paysasa\Payments\Exceptions\ProviderApiException;

/**
 * Thin, provider-faithful HTTP client for the Safaricom Daraja API. Knows
 * nothing about Paysasa's DTOs/enums — MpesaDriver is the adapter that
 * translates between this client's raw request/response shapes and the
 * package's unified contracts. Keeping this separation means the Daraja
 * wire format can evolve (Safaricom does version its APIs) without
 * touching driver-facing code.
 */
class DarajaClient
{
    public function __construct(
        protected string $baseUrl,
        protected DarajaAuthenticator $auth,
    ) {
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->auth->token())
            ->acceptJson()
            ->timeout(30);
    }

    protected function password(string $shortcode, string $passkey, string $timestamp): string
    {
        return base64_encode($shortcode.$passkey.$timestamp);
    }

    /** Lipa Na M-Pesa Online (STK Push) — prompts the customer's phone for their PIN. */
    public function stkPush(array $params): array
    {
        $timestamp = Carbon::now()->format('YmdHis');

        $response = $this->http()->post('/mpesa/stkpush/v1/processrequest', [
            'BusinessShortCode' => $params['shortcode'],
            'Password' => $this->password($params['shortcode'], $params['passkey'], $timestamp),
            'Timestamp' => $timestamp,
            'TransactionType' => $params['transaction_type'] ?? 'CustomerPayBillOnline',
            'Amount' => (int) $params['amount'],
            'PartyA' => $params['phone'],
            'PartyB' => $params['shortcode'],
            'PhoneNumber' => $params['phone'],
            'CallBackURL' => $params['callback_url'],
            'AccountReference' => $params['reference'],
            'TransactionDesc' => $params['description'] ?? 'Payment',
        ]);

        return $this->decode($response, 'STK push');
    }

    /** Query the outcome of a previously initiated STK push. */
    public function stkQuery(string $shortcode, string $passkey, string $checkoutRequestId): array
    {
        $timestamp = Carbon::now()->format('YmdHis');

        $response = $this->http()->post('/mpesa/stkpushquery/v1/query', [
            'BusinessShortCode' => $shortcode,
            'Password' => $this->password($shortcode, $passkey, $timestamp),
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ]);

        return $this->decode($response, 'STK query');
    }

    /** Business-to-Customer disbursement (payouts, refunds, salaries). */
    public function b2c(array $params): array
    {
        $response = $this->http()->post('/mpesa/b2c/v1/paymentrequest', [
            'InitiatorName' => $params['initiator_name'],
            'SecurityCredential' => $params['security_credential'],
            'CommandID' => $params['command_id'] ?? 'BusinessPayment',
            'Amount' => (int) $params['amount'],
            'PartyA' => $params['shortcode'],
            'PartyB' => $params['phone'],
            'Remarks' => $params['remarks'] ?? 'Payout',
            'QueueTimeOutURL' => $params['timeout_url'],
            'ResultURL' => $params['result_url'],
            'Occasion' => $params['occasion'] ?? '',
        ]);

        return $this->decode($response, 'B2C payment');
    }

    /** Transaction Status query — used by MpesaDriver::verify() when no CheckoutRequestID is available (e.g. C2B). */
    public function transactionStatus(array $params): array
    {
        $response = $this->http()->post('/mpesa/transactionstatus/v1/query', [
            'Initiator' => $params['initiator_name'],
            'SecurityCredential' => $params['security_credential'],
            'CommandID' => 'TransactionStatusQuery',
            'TransactionID' => $params['transaction_id'],
            'PartyA' => $params['shortcode'],
            'IdentifierType' => '4',
            'ResultURL' => $params['result_url'],
            'QueueTimeOutURL' => $params['timeout_url'],
            'Remarks' => 'Status check',
            'Occasion' => '',
        ]);

        return $this->decode($response, 'transaction status query');
    }

    public function reversal(array $params): array
    {
        $response = $this->http()->post('/mpesa/reversal/v1/request', [
            'Initiator' => $params['initiator_name'],
            'SecurityCredential' => $params['security_credential'],
            'CommandID' => 'TransactionReversal',
            'TransactionID' => $params['transaction_id'],
            'Amount' => (int) $params['amount'],
            'ReceiverParty' => $params['shortcode'],
            'RecieverIdentifierType' => '4',
            'ResultURL' => $params['result_url'],
            'QueueTimeOutURL' => $params['timeout_url'],
            'Remarks' => $params['remarks'] ?? 'Reversal',
            'Occasion' => '',
        ]);

        return $this->decode($response, 'reversal');
    }

    public function accountBalance(array $params): array
    {
        $response = $this->http()->post('/mpesa/accountbalance/v1/query', [
            'Initiator' => $params['initiator_name'],
            'SecurityCredential' => $params['security_credential'],
            'CommandID' => 'AccountBalance',
            'PartyA' => $params['shortcode'],
            'IdentifierType' => '4',
            'Remarks' => 'Balance check',
            'QueueTimeOutURL' => $params['timeout_url'],
            'ResultURL' => $params['result_url'],
        ]);

        return $this->decode($response, 'account balance query');
    }

    /** Registers the C2B validation/confirmation URLs against a shortcode (one-time setup, typically run via artisan command). */
    public function registerC2bUrls(array $params): array
    {
        $response = $this->http()->post('/mpesa/c2b/v1/registerurl', [
            'ShortCode' => $params['shortcode'],
            'ResponseType' => $params['response_type'] ?? 'Completed',
            'ConfirmationURL' => $params['confirmation_url'],
            'ValidationURL' => $params['validation_url'],
        ]);

        return $this->decode($response, 'C2B URL registration');
    }

    protected function decode(Response $response, string $context): array
    {
        if ($response->failed()) {
            throw new ProviderApiException(
                "Daraja API error during {$context}: HTTP {$response->status()}",
                $response->status(),
                $response->json() ?? $response->body(),
            );
        }

        return $response->json() ?? [];
    }
}
