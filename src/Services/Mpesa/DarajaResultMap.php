<?php

declare(strict_types=1);

namespace Paysasa\Payments\Services\Mpesa;

use Paysasa\Payments\Enums\PaymentStatus;

class DarajaResultMap
{
    /**
     * Map Daraja ResultCode to canonical PaymentStatus.
     *
     * 0 -> Successful
     * 1032 -> Cancelled (Customer cancelled)
     * 1037 -> Expired (DS timeout / no response from customer)
     * default -> Failed
     */
    public static function map(int|string|null $resultCode): PaymentStatus
    {
        if ($resultCode === null) {
            return PaymentStatus::Pending;
        }

        return match ((int) $resultCode) {
            0 => PaymentStatus::Successful,
            1032 => PaymentStatus::Cancelled,
            1037 => PaymentStatus::Expired,
            default => PaymentStatus::Failed,
        };
    }

    /**
     * Return normalized failure code string for tracking and debugging.
     */
    public static function failureCode(int|string|null $resultCode): ?string
    {
        if ($resultCode === null || (int) $resultCode === 0) {
            return null;
        }

        return match ((int) $resultCode) {
            1 => 'insufficient_funds',
            1032 => 'user_cancelled',
            1037 => 'timeout_expired',
            2001 => 'wrong_pin',
            default => 'daraja_error_'.((string) $resultCode),
        };
    }
}
