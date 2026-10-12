<?php

declare(strict_types=1);

use Paysasa\Payments\Enums\Currency;
use Paysasa\Payments\Exceptions\InvalidAmountException;
use Paysasa\Payments\ValueObjects\Money;

it('creates Money from integers, floats and numeric strings', function () {
    $m1 = Money::of(100, Currency::KES);
    expect($m1->minor())->toBe(10000)
        ->and($m1->amountMajor())->toBe(100.0)
        ->and($m1->major())->toBe('100.00')
        ->and($m1->wholeUnits())->toBe(100);

    $m2 = Money::of(100.75, Currency::KES);
    expect($m2->minor())->toBe(10075)
        ->and($m2->amountMajor())->toBe(100.75)
        ->and($m2->major())->toBe('100.75');

    $m3 = Money::of('250.50', Currency::KES);
    expect($m3->minor())->toBe(25050)
        ->and($m3->amountMajor())->toBe(250.50);
});

it('throws on fractional units when wholeUnits is called', function () {
    $money = Money::of(100.75, Currency::KES);

    expect(fn () => $money->wholeUnits())->toThrow(InvalidAmountException::class);
});

it('throws on precision beyond currency exponent', function () {
    expect(fn () => Money::of(100.755, Currency::KES))->toThrow(InvalidAmountException::class);
});

it('handles zero-decimal currencies like RWF correctly', function () {
    $rwf = Money::of(1500, Currency::RWF);

    expect($rwf->minor())->toBe(1500)
        ->and($rwf->amountMajor())->toBe(1500.0)
        ->and($rwf->major())->toBe('1500')
        ->and($rwf->wholeUnits())->toBe(1500);
});

it('throws on non-positive amounts', function () {
    expect(fn () => Money::of(0, Currency::KES))->toThrow(InvalidAmountException::class)
        ->and(fn () => Money::of(-50, Currency::KES))->toThrow(InvalidAmountException::class);
});
