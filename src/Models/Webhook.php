<?php

declare(strict_types=1);

namespace Paysasa\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Paysasa\Payments\Enums\WebhookStatus;
use Paysasa\Payments\Traits\HasUuid;

class Webhook extends Model
{
    use HasUuid;

    protected $fillable = [
        'payment_id', 'provider', 'event_type', 'provider_event_id', 'status',
        'signature', 'signature_valid', 'headers', 'payload', 'ip_address',
        'error_message', 'verified_at', 'processed_at',
    ];

    protected $casts = [
        'status' => WebhookStatus::class,
        'headers' => 'array',
        'payload' => 'array',
        'signature_valid' => 'boolean',
        'verified_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
