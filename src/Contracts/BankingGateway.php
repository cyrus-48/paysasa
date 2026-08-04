<?php

declare(strict_types=1);

namespace Paysasa\Payments\Contracts;

use Paysasa\Payments\DTOs\ChargeRequest;
use Paysasa\Payments\DTOs\PaymentResponse;

/**
 * Extension point for bank rails (PesaLink, EFT, RTGS, direct
 * host-to-host bank APIs). Unlike M-Pesa/Stripe, no single public API
 * standard covers Kenyan banks — each acquiring bank issues its own
 * contract-gated API. Implement this per bank/rail by extending
 * Drivers\Banking\AbstractBankingDriver, which already fulfils
 * PaymentDriver + PayoutCapable + this interface's shared plumbing
 * (reference generation, status polling, logging).
 */
interface BankingGateway
{
    public function resolveAccount(string $accountNumber, string $bankCode): array;

    public function transfer(ChargeRequest $request): PaymentResponse;
}
