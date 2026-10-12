<?php

declare(strict_types=1);

namespace Paysasa\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Paysasa\Payments\Enums\WebhookStatus;
use Paysasa\Payments\Traits\HasUuid;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $payment_id
 * @property string $provider
 * @property string|null $event_type
 * @property string|null $provider_event_id
 * @property string|null $dedupe_key
 * @property WebhookStatus $status
 * @property string|null $signature
 * @property bool|null $signature_valid
 * @property array|null $headers
 * @property array $payload
 * @property string|null $ip_address
 * @property string|null $error_message
 * @property int $match_attempts
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Webhook extends Model
{
    use HasUuid;

    protected $fillable = [
        'payment_id', 'provider', 'event_type', 'provider_event_id', 'dedupe_key', 'status',
        'signature', 'signature_valid', 'headers', 'payload', 'ip_address',
        'error_message', 'match_attempts', 'verified_at', 'processed_at',
    ];

    protected $casts = [
        'status' => WebhookStatus::class,
        'headers' => 'array',
        'payload' => 'array',
        'match_attempts' => 'integer',
        'signature_valid' => 'boolean',
        'verified_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
