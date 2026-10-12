<?php

declare(strict_types=1);

use Paysasa\Payments\Exceptions\InvalidPhoneNumberException;
use Paysasa\Payments\ValueObjects\KenyanMsisdn;

it('normalizes valid Kenyan phone numbers into 12-digit format', function (string $input, string $expected) {
    expect(KenyanMsisdn::of($input)->toString())->toBe($expected);
})->with([
    ['0712345678', '254712345678'],
    ['0112345678', '254112345678'],
    ['+254 712 345 678', '254712345678'],
    ['254712345678', '254712345678'],
    ['712345678', '254712345678'],
    ['+254-112-345-678', '254112345678'],
]);

it('throws InvalidPhoneNumberException for invalid phone numbers', function (string $invalid) {
    expect(fn () => KenyanMsisdn::of($invalid))->toThrow(InvalidPhoneNumberException::class);
})->with([
    '0712',
    '0812345678',
    '12345',
    '2540712345678',
    '07000000000000',
    'random_string',
]);
