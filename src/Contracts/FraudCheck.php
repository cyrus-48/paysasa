<?php

declare(strict_types=1);

namespace Paysasa\Payments\Contracts;

use Paysasa\Payments\DTOs\ChargeRequest;

/**
 * Implement and list in config('paysasa.fraud_checks') to run custom
 * pre-charge screening (velocity limits, blocklists, geo checks...).
 * Throw Exceptions\FraudSuspectedException to abort the charge.
 */
interface FraudCheck
{
    /** @throws \Paysasa\Payments\Exceptions\FraudSuspectedException */
    public function check(ChargeRequest $request): void;
}
