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

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [
                self::Processing,
                self::Authorized,
                self::Successful,
                self::Failed,
                self::Cancelled,
                self::Expired,
            ],
            self::Processing => [
                self::Authorized,
                self::Successful,
                self::Failed,
                self::Cancelled,
                self::Expired,
            ],
            self::Authorized => [
                self::Successful,
                self::Cancelled,
                self::Failed,
                self::Expired,
            ],
            self::Successful => [
                self::Refunded,
                self::PartiallyRefunded,
                self::Reversed,
            ],
            self::PartiallyRefunded => [
                self::PartiallyRefunded,
                self::Refunded,
            ],
            self::Expired => [
                self::Successful, // late confirmation: money moved
            ],
            self::Failed, self::Cancelled,
            self::Refunded, self::Reversed => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }
}
