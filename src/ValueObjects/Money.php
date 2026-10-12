<?php

declare(strict_types=1);

namespace Paysasa\Payments\ValueObjects;

use Paysasa\Payments\Enums\Currency;
use Paysasa\Payments\Exceptions\InvalidAmountException;

final readonly class Money
{
    private function __construct(
        public int $minor,
        public Currency $currency,
    ) {
    }

    public static function fromMinor(int $minor, Currency $currency): self
    {
        if ($minor <= 0) {
            throw InvalidAmountException::nonPositive($minor);
        }

        return new self($minor, $currency);
    }

    public static function of(int|float|string $amount, Currency $currency): self
    {
        if (is_string($amount)) {
            if (! is_numeric($amount)) {
                throw InvalidAmountException::nonPositive($amount);
            }
            $amount = (float) $amount;
        }

        if ($amount <= 0) {
            throw InvalidAmountException::nonPositive($amount);
        }

        $exponent = $currency->minorUnitExponent();
        $factor = 10 ** $exponent;

        $scaled = $amount * $factor;
        $rounded = round($scaled);
        if (abs($scaled - $rounded) > 1e-6) {
            throw InvalidAmountException::hiddenPrecision((float) $amount, $exponent);
        }

        return new self((int) $rounded, $currency);
    }

    public function minor(): int
    {
        return $this->minor;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function major(): string
    {
        $exponent = $this->currency->minorUnitExponent();
        if ($exponent === 0) {
            return (string) $this->minor;
        }

        $divisor = 10 ** $exponent;
        $major = $this->minor / $divisor;

        return number_format($major, $exponent, '.', '');
    }

    public function amountMajor(): float
    {
        $exponent = $this->currency->minorUnitExponent();
        if ($exponent === 0) {
            return (float) $this->minor;
        }

        return $this->minor / (10 ** $exponent);
    }

    public function wholeUnits(): int
    {
        $exponent = $this->currency->minorUnitExponent();
        if ($exponent === 0) {
            return $this->minor;
        }

        $divisor = 10 ** $exponent;
        if ($this->minor % $divisor !== 0) {
            throw InvalidAmountException::fractionalUnitsNotAllowed($this->minor, $exponent);
        }

        return intdiv($this->minor, $divisor);
    }
}
