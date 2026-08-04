<?php

declare(strict_types=1);

namespace Paysasa\Payments\Contracts;

use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;

/** Two-phase card flow: hold funds now, capture (or void) later. */
interface Authorizable
{
    public function authorize(ChargeRequest $request): PaymentResponse;

    public function capture(string $providerReference, ?float $amount = null): PaymentResponse;

    public function void(string $providerReference): PaymentResponse;
}
