<?php

declare(strict_types=1);

namespace Paysasa\Payments\Traits;

use Paysasa\Payments\Enums\Currency;

/**
 * Models store amounts in minor units (`amount_minor`, an integer) to
 * avoid floating-point rounding drift, but the rest of the package (DTOs,
 * fluent API) works in major units (e.g. 2500.00 KES). This trait bridges
 * the two via a virtual `amount` accessor/mutator.
 */
trait HasMinorUnitAmount
{
    public function getAmountAttribute(): float
    {
        $currency = Currency::tryFrom($this->currency ?? 'KES') ?? Currency::KES;

        return $currency->fromMinorUnits((int) $this->amount_minor);
    }

    public function setAmountAttribute(float $value): void
    {
        $currency = Currency::tryFrom($this->attributes['currency'] ?? 'KES') ?? Currency::KES;
        $this->attributes['amount_minor'] = $currency->toMinorUnits($value);
    }
}
