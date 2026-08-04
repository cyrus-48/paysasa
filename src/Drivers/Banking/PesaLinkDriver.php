<?php

declare(strict_types=1);

namespace Paysasa\Payments\Drivers\Banking;

use Paysasa\Payments\Enums\PaymentProvider;

/**
 * PesaLink is the Integrated Payment Services Limited (IPSL) real-time
 * interbank switch used by Kenyan banks. Most banks expose it to
 * merchants through their own corporate/API banking product (there is no
 * single merchant-facing PesaLink API) — configure `base_urls` in
 * config/paysasa.php with your bank's actual PesaLink gateway endpoint.
 */
class PesaLinkDriver extends AbstractBankingDriver
{
    public function provider(): PaymentProvider
    {
        return PaymentProvider::PesaLink;
    }

    protected function endpointPrefix(): string
    {
        return '/pesalink/v1';
    }
}
