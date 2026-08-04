<?php

declare(strict_types=1);

namespace Paysasa\Payments\Actions;

use Paysasa\Payments\Contracts\FraudCheck;
use Paysasa\Payments\DTOs\ChargeRequest;

/**
 * Resolves and runs every class listed in config('paysasa.fraud_checks')
 * in order. Any one of them may throw FraudSuspectedException to abort
 * the charge — see Contracts\FraudCheck.
 */
class RunFraudChecks
{
    public function __construct(protected array $checks)
    {
    }

    public function execute(ChargeRequest $request): void
    {
        foreach ($this->checks as $checkClass) {
            /** @var FraudCheck $check */
            $check = app($checkClass);
            $check->check($request);
        }
    }
}
