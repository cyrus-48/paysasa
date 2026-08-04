<?php

declare(strict_types=1);

namespace Paysasa\Payments\Support;

use Paysasa\Payments\Models\ProviderAccount;

/**
 * Resolves the effective credential/config array for a driver, merging
 * (in precedence order):
 *   1. an active `provider_accounts` row for the given merchant_id+provider
 *      (decrypted on read, via the model's AsEncryptedCollection cast)
 *   2. config('paysasa.drivers.{provider}') as the fallback/default
 *
 * This is what makes Multi-Merchant support possible without every driver
 * having to know about provider_accounts itself.
 */
class CredentialVault
{
    public function __construct(protected array $baseConfig)
    {
    }

    public function resolve(string $provider, ?string $merchantId = null): array
    {
        $defaults = $this->baseConfig['drivers'][$provider] ?? [];

        if ($merchantId === null) {
            return $defaults;
        }

        $account = ProviderAccount::query()
            ->where('merchant_id', $merchantId)
            ->where('provider', $provider)
            ->where('is_active', true)
            ->when(true, fn ($q) => $q->orderByDesc('is_default'))
            ->first();

        if ($account === null) {
            return $defaults;
        }

        return array_merge(
            $defaults,
            $account->credentials?->toArray() ?? [],
            $account->settings ?? [],
            ['env' => $account->environment],
        );
    }
}
