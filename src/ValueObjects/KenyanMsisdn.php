<?php

declare(strict_types=1);

namespace Paysasa\Payments\ValueObjects;

use Paysasa\Payments\Exceptions\InvalidPhoneNumberException;

final readonly class KenyanMsisdn
{
    private function __construct(public string $msisdn)
    {
    }

    public static function of(string $raw): self
    {
        $cleaned = preg_replace('/[\s+\-\(\)]/', '', $raw) ?? '';

        if (preg_match('/^0([71]\d{8})$/', $cleaned, $matches)) {
            $cleaned = '254' . $matches[1];
        } elseif (preg_match('/^([71]\d{8})$/', $cleaned, $matches)) {
            $cleaned = '254' . $matches[1];
        } elseif (preg_match('/^254([71]\d{8})$/', $cleaned)) {
            // Valid 12-digit format
        } else {
            throw InvalidPhoneNumberException::invalidFormat($raw);
        }

        if (strlen($cleaned) !== 12 || ! ctype_digit($cleaned)) {
            throw InvalidPhoneNumberException::invalidFormat($raw);
        }

        return new self($cleaned);
    }

    public function toString(): string
    {
        return $this->msisdn;
    }

    public function __toString(): string
    {
        return $this->msisdn;
    }
}
