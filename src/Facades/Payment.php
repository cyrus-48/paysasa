<?php

declare(strict_types=1);

namespace Paysasa\Payments\Facades;

use Illuminate\Support\Facades\Facade;
use Paysasa\Payments\DTOs\PaymentResponse;
use Paysasa\Payments\Managers\PaymentManager;
use Paysasa\Payments\Support\FluentPaymentBuilder;
use Paysasa\Payments\Testing\PaymentFake;

/**
 * @method static FluentPaymentBuilder driver(?string $driver = null)
 * @method static PaymentManager forMerchant(string $merchantId)
 * @method static PaymentManager extend(string $driver, \Closure $callback)
 * @method static FluentPaymentBuilder amount(int|float $amount)
 * @method static FluentPaymentBuilder currency(string $currency)
 * @method static FluentPaymentBuilder phone(string $phone)
 * @method static FluentPaymentBuilder customer(mixed $customer)
 * @method static FluentPaymentBuilder reference(string $reference)
 * @method static FluentPaymentBuilder description(string $description)
 * @method static FluentPaymentBuilder metadata(array $metadata)
 * @method static PaymentResponse charge()
 *
 * @see PaymentManager
 */
class Payment extends Facade
{
    public static function fake(): PaymentFake
    {
        $fake = new PaymentFake();

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return PaymentManager::class;
    }
}
