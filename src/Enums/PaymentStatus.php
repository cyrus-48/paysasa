<?php

declare(strict_types=1);

namespace Paysasa\Payments\Enums;

/**
 * Canonical status a Payment can be in, independent of any provider's own
 * vocabulary. Every driver maps its provider-native status strings onto
 * this set — see AbstractDriver::mapStatus().
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Authorized = 'authorized';
    case Successful = 'successful';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Reversed = 'reversed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Successful, self::Failed, self::Cancelled,
            self::Expired, self::Refunded, self::Reversed => true,
            default => false,
        };
    }

    public function isSuccessful(): bool
    {
        return in_array($this, [self::Successful, self::Refunded, self::PartiallyRefunded], true);
    }
}
