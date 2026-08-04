<?php

declare(strict_types=1);

namespace Paysasa\Payments\Enums;

enum Currency: string
{
    case KES = 'KES';
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case UGX = 'UGX';
    case TZS = 'TZS';
    case RWF = 'RWF';

    /** Minor unit exponent, i.e. how many decimal places the smallest unit represents. */
    public function minorUnitExponent(): int
    {
        return match ($this) {
            self::RWF => 0,
            default => 2,
        };
    }

    public function toMinorUnits(float $amount): int
    {
        return (int) round($amount * (10 ** $this->minorUnitExponent()));
    }

    public function fromMinorUnits(int $amount): float
    {
        return $amount / (10 ** $this->minorUnitExponent());
    }
}
