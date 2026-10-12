<?php

declare(strict_types=1);

namespace Paysasa\Payments\Exceptions;

class InvalidPhoneNumberException extends PaymentException
{
    public static function invalidFormat(string $phone): self
    {
        return new self("The phone number [{$phone}] is not a valid Kenyan MSISDN.");
    }
}
