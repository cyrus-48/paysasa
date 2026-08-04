<?php

declare(strict_types=1);

namespace Paysasa\Payments\Contracts;

use Paysasa\Payments\DTOs\BalanceResponse;

interface BalanceInquirable
{
    public function balance(): BalanceResponse;
}
