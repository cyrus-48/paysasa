<?php

declare(strict_types=1);

namespace Paysasa\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Paysasa\Payments\Traits\HasUuid;

class PaymentMethod extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'owner_type', 'owner_id', 'provider', 'type', 'token', 'display_label',
        'brand', 'last_four', 'expiry_month', 'expiry_year', 'phone',
        'is_default', 'verified_at', 'metadata',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'verified_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $hidden = ['token'];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
