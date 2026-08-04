<?php

declare(strict_types=1);

namespace Paysasa\Payments\Webhooks;

use Paysasa\Payments\Contracts\SignatureVerifier;
use Paysasa\Payments\Exceptions\DriverNotFoundException;
use Paysasa\Payments\Webhooks\Verifiers\FlutterwaveSignatureVerifier;
use Paysasa\Payments\Webhooks\Verifiers\HmacHeaderSignatureVerifier;
use Paysasa\Payments\Webhooks\Verifiers\MpesaSignatureVerifier;
use Paysasa\Payments\Webhooks\Verifiers\PaystackSignatureVerifier;
use Paysasa\Payments\Webhooks\Verifiers\PesapalSignatureVerifier;
use Paysasa\Payments\Webhooks\Verifiers\StripeSignatureVerifier;

/**
 * Strategy Pattern registry: resolves the correct SignatureVerifier for an
 * inbound webhook by provider name. WebhookController never needs an
 * if/else chain of its own — it just asks this class for "the verifier for
 * this provider" and calls verify().
 */
class WebhookDispatcher
{
    public function __construct(protected array $config)
    {
    }

    public function verifierFor(string $provider): SignatureVerifier
    {
        $driverConfig = $this->config['drivers'][$provider] ?? [];

        return match ($provider) {
            'stripe' => new StripeSignatureVerifier($driverConfig['webhook_secret'] ?? ''),
            'paystack' => new PaystackSignatureVerifier($driverConfig['secret_key'] ?? ''),
            'flutterwave' => new FlutterwaveSignatureVerifier($driverConfig['webhook_secret_hash'] ?? ''),
            'mpesa' => new MpesaSignatureVerifier($driverConfig['webhook_ip_allowlist'] ?? []),
            'pesapal' => new PesapalSignatureVerifier(),
            'airtel', 'tkash', 'pesalink', 'eft', 'rtgs', 'virtual_account' => new HmacHeaderSignatureVerifier(
                $provider,
                $driverConfig['webhook_secret'] ?? '',
            ),
            default => throw DriverNotFoundException::make($provider),
        };
    }
}
