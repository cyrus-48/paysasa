<?php

declare(strict_types=1);

namespace Paysasa\Payments\Models;

use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Paysasa\Payments\Traits\HasUuid;

class ProviderAccount extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'merchant_id', 'provider', 'label', 'environment', 'is_active',
        'is_default', 'credentials', 'settings', 'credentials_rotated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        // Encrypted at rest; only decrypted in-process, never logged. See Support\CredentialVault.
        'credentials' => AsEncryptedCollection::class,
        'settings' => 'array',
        'credentials_rotated_at' => 'datetime',
    ];

    protected $hidden = ['credentials'];

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
