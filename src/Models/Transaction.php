<?php

declare(strict_types=1);

namespace Paysasa\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Enums\TransactionType;
use Paysasa\Payments\Traits\HasMinorUnitAmount;
use Paysasa\Payments\Traits\HasUuid;

class Transaction extends Model
{
    use HasMinorUnitAmount;
    use HasUuid;

    protected $fillable = [
        'payment_id', 'type', 'status', 'amount', 'amount_minor', 'currency',
        'provider_reference', 'raw_request', 'raw_response', 'duration_ms',
    ];

    protected $casts = [
        'type' => TransactionType::class,
        'status' => PaymentStatus::class,
        'raw_request' => 'array',
        'raw_response' => 'array',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
