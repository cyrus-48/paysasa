<?php

declare(strict_types=1);

namespace Paysasa\Payments\Contracts;

use Paysasa\Payments\DTOs\RefundRequest;
use Paysasa\Payments\DTOs\RefundResponse;

interface Refundable
{
    /** Partial when $request->amount is set and less than the original charge; full otherwise. */
    public function refund(RefundRequest $request): RefundResponse;
}
