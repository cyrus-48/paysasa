<?php

declare(strict_types=1);

namespace Paysasa\Payments\Enums;

enum PaymentCategory: string
{
    case MobileMoney = 'mobile_money';
    case Card = 'card';
    case Wallet = 'wallet';
    case Banking = 'banking';
}
