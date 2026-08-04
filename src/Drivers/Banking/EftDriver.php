<?php

declare(strict_types=1);

namespace Paysasa\Payments\Drivers\Banking;

use Paysasa\Payments\Enums\PaymentProvider;

/**
 * Electronic Funds Transfer — same-bank or batch/next-business-day
 * interbank transfer via a bank's corporate API. Slower and typically
 * cheaper than PesaLink; still exposed through a bank-specific endpoint.
 */
class EftDriver extends AbstractBankingDriver
{
    public function provider(): PaymentProvider
    {
        return PaymentProvider::Eft;
    }

    protected function endpointPrefix(): string
    {
        return '/eft/v1';
    }
}
