<?php

declare(strict_types=1);

namespace Paysasa\Payments\Enums;

enum WebhookStatus: string
{
    case Received = 'received';
    case VerificationFailed = 'verification_failed';
    case Queued = 'queued';
    case Processing = 'processing';
    case Processed = 'processed';
    case Unmatched = 'unmatched';
    case Failed = 'failed';
    case Ignored = 'ignored'; // duplicate / replay
}
