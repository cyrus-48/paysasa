<?php

declare(strict_types=1);

namespace Paysasa\Payments\Exceptions;

class InvalidConfigurationException extends PaymentException
{
    public static function missingKeys(string $driver, array $keys): self
    {
        $list = implode(', ', $keys);

        return new self("Driver [{$driver}] is missing required configuration: {$list}.", [
            'driver' => $driver,
            'missing_keys' => $keys,
        ]);
    }
}
