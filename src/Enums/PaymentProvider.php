<?php

declare(strict_types=1);

namespace Paysasa\Payments\Enums;

enum PaymentProvider: string
{
    case Mpesa = 'mpesa';
    case Airtel = 'airtel';
    case TKash = 'tkash';
    case Stripe = 'stripe';
    case Pesapal = 'pesapal';
    case Flutterwave = 'flutterwave';
    case Paystack = 'paystack';
    case GooglePay = 'google_pay';
    case ApplePay = 'apple_pay';
    case PesaLink = 'pesalink';
    case Eft = 'eft';
    case Rtgs = 'rtgs';
    case VirtualAccount = 'virtual_account';

    public function category(): PaymentCategory
    {
        return match ($this) {
            self::Mpesa, self::Airtel, self::TKash => PaymentCategory::MobileMoney,
            self::Stripe, self::Pesapal, self::Flutterwave, self::Paystack => PaymentCategory::Card,
            self::GooglePay, self::ApplePay => PaymentCategory::Wallet,
            self::PesaLink, self::Eft, self::Rtgs, self::VirtualAccount => PaymentCategory::Banking,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Mpesa => 'M-Pesa',
            self::Airtel => 'Airtel Money',
            self::TKash => 'T-Kash',
            self::Stripe => 'Stripe',
            self::Pesapal => 'Pesapal',
            self::Flutterwave => 'Flutterwave',
            self::Paystack => 'Paystack',
            self::GooglePay => 'Google Pay',
            self::ApplePay => 'Apple Pay',
            self::PesaLink => 'PesaLink',
            self::Eft => 'EFT',
            self::Rtgs => 'RTGS',
            self::VirtualAccount => 'Virtual Account',
        };
    }
}
