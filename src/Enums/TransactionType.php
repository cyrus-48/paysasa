<?php

declare(strict_types=1);

namespace Paysasa\Payments\Enums;

enum TransactionType: string
{
    case Charge = 'charge';
    case Authorization = 'authorization';
    case Capture = 'capture';
    case Refund = 'refund';
    case Reversal = 'reversal';
    case Payout = 'payout';       // B2C / disbursement
    case Transfer = 'transfer';   // B2B / bank transfer
    case BalanceInquiry = 'balance_inquiry';
    case StatusQuery = 'status_query';
}
