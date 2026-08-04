<?php

declare(strict_types=1);

namespace Paysasa\Payments\Contracts;

use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;

/**
 * Business-to-Customer / Business-to-Business disbursements — moving money
 * OUT of the merchant account (payroll, refunds-as-payout, supplier
 * payments), as opposed to charge() which collects money IN.
 */
interface PayoutCapable
{
    public function payout(ChargeRequest $request): PaymentResponse;
}
