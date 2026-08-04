<?php

declare(strict_types=1);

namespace Paysasa\Payments\Support;

use Illuminate\Database\Eloquent\Model;
use Paysasa\Payments\Models\AuditLog;

/** Append-only writer for audit_logs — see the migration for why it's never updated/deleted. */
class AuditLogger
{
    public function record(
        string $action,
        ?Model $auditable = null,
        array $oldValues = [],
        array $newValues = [],
        ?Model $actor = null,
        ?string $actorLabel = null,
    ): AuditLog {
        return AuditLog::create([
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'actor_label' => $actorLabel ?? ($actor === null ? 'system' : null),
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
