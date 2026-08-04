<?php

declare(strict_types=1);

namespace Paysasa\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Paysasa\Payments\Enums\RefundStatus;
use Paysasa\Payments\Traits\HasMinorUnitAmount;
use Paysasa\Payments\Traits\HasUuid;

class Refund extends Model
{
    use HasMinorUnitAmount;
    use HasUuid;

    protected $fillable = [
        'payment_id', 'transaction_id', 'amount', 'amount_minor', 'currency', 'status',
        'reason', 'provider_reference', 'raw_response', 'initiated_by',
    ];

    protected $casts = [
        'status' => RefundStatus::class,
        'raw_response' => 'array',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
