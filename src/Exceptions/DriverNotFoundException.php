<?php

declare(strict_types=1);

namespace Paysasa\Payments\Exceptions;

class DriverNotFoundException extends PaymentException
{
    public static function make(string $driver): self
    {
        return new self("Payment driver [{$driver}] is not registered. Check config/paysasa.php or PaymentManager::extend().", [
            'driver' => $driver,
        ]);
    }
}
