<?php

declare(strict_types=1);

namespace Paysasa\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Paysasa\Payments\Traits\HasUuid;

class PaymentLog extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $fillable = ['payment_id', 'provider', 'level', 'message', 'context'];

    protected $casts = ['context' => 'array'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
