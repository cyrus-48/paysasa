<?php

declare(strict_types=1);

namespace Paysasa\Payments\Enums;

enum PaymentProvider: string
{
    case Mpesa = 'mpesa';
    case Airtel = 'airtel';
    case Stripe = 'stripe';
    case Pesapal = 'pesapal';
    case Flutterwave = 'flutterwave';
    case Paystack = 'paystack';
    case PesaLink = 'pesalink';

    public function category(): PaymentCategory
    {
        return match ($this) {
            self::Mpesa, self::Airtel => PaymentCategory::MobileMoney,
            self::Stripe, self::Pesapal, self::Flutterwave, self::Paystack => PaymentCategory::Card,
            self::PesaLink => PaymentCategory::Banking,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Mpesa => 'M-Pesa',
            self::Airtel => 'Airtel Money',
            self::Stripe => 'Stripe',
            self::Pesapal => 'Pesapal',
            self::Flutterwave => 'Flutterwave',
            self::Paystack => 'Paystack',
            self::PesaLink => 'PesaLink',
        };
    }
}
