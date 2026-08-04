<?php

declare(strict_types=1);

namespace Paysasa\Payments\Contracts;

/**
 * OAuth2 / client-credential token acquisition and caching, used by
 * providers (Daraja, Airtel, Pesapal) that require a bearer token minted
 * from consumer key/secret before every API call.
 */
interface TokenProvider
{
    public function token(): string;

    public function forceRefresh(): string;
}
