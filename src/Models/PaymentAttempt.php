<?php

declare(strict_types=1);

namespace Paysasa\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Traits\HasUuid;

class PaymentAttempt extends Model
{
    use HasUuid;

    protected $fillable = [
        'payment_id', 'attempt_number', 'status', 'provider_reference',
        'error_code', 'error_message', 'ip_address', 'user_agent', 'raw_response',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'raw_response' => 'array',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
