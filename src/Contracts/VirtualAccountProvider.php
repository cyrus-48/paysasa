<?php

declare(strict_types=1);

namespace Paysasa\Payments\Contracts;

/** Generates and manages dedicated/virtual bank account numbers for collections. */
interface VirtualAccountProvider
{
    public function createVirtualAccount(string $customerReference, array $attributes = []): array;

    public function deactivateVirtualAccount(string $accountNumber): void;
}
