<?php

declare(strict_types=1);

use Paysasa\Payments\Enums\Currency;

it('converts KES major units to minor units without float drift', function () {
    expect(Currency::KES->toMinorUnits(2500.50))->toBe(250050)
        ->and(Currency::KES->fromMinorUnits(250050))->toBe(2500.5);
});

it('round-trips major -> minor -> major exactly', function () {
    $major = 1999.99;
    $minor = Currency::KES->toMinorUnits($major);

    expect($minor)->toBe(199999)
        ->and(Currency::KES->fromMinorUnits($minor))->toBe(1999.99);
});

it('treats RWF as a zero-decimal currency', function () {
    expect(Currency::RWF->toMinorUnits(500))->toBe(500)
        ->and(Currency::RWF->minorUnitExponent())->toBe(0);
});
