<?php

declare(strict_types=1);

namespace Paysasa\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Paysasa\Payments\Enums\PaymentCategory;
use Paysasa\Payments\Enums\PaymentProvider;
use Paysasa\Payments\Enums\PaymentStatus;
use Paysasa\Payments\Traits\HasMinorUnitAmount;
use Paysasa\Payments\Traits\HasUuid;

class Payment extends Model
{
    use HasMinorUnitAmount;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'provider_account_id', 'merchant_id', 'provider', 'category', 'type', 'status',
        'amount', 'amount_minor', 'currency', 'reference', 'provider_reference', 'receipt_number',
        'idempotency_key', 'customer_id', 'customer_name', 'customer_email', 'customer_phone',
        'payable_type', 'payable_id', 'description', 'metadata', 'splits', 'callback_url',
        'failure_reason', 'failure_code', 'initiated_at', 'completed_at', 'expires_at',
    ];

    protected $casts = [
        'provider' => PaymentProvider::class,
        'category' => PaymentCategory::class,
        'status' => PaymentStatus::class,
        'metadata' => 'array',
        'splits' => 'array',
        'initiated_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PaymentLog::class);
    }

    public function providerAccount(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForProvider($query, PaymentProvider|string $provider)
    {
        return $query->where('provider', $provider instanceof PaymentProvider ? $provider->value : $provider);
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', PaymentStatus::Successful->value);
    }
}
