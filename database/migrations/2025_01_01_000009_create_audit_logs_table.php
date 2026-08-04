<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tamper-evident trail of sensitive, non-transactional actions: refund
 * approvals, credential rotation on provider_accounts, config changes,
 * manual status overrides. Distinct from payment_logs (system/API
 * activity) — this table is about WHO did WHAT to WHICH record, for
 * compliance review. Rows are append-only at the application layer: the
 * package never updates or deletes an audit_logs row once written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Polymorphic actor: host app's admin/user model, or 'system' for automated actions.
            $table->nullableUuidMorphs('actor');
            $table->string('actor_label')->nullable(); // fallback human-readable actor when no model exists (e.g. "system:webhook")

            $table->string('action'); // e.g. 'refund.issued', 'credentials.rotated', 'payment.status.overridden'

            // Polymorphic subject of the action.
            $table->nullableUuidMorphs('auditable');

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamp('created_at');

            // nullableUuidMorphs() above already indexes (auditable_type, auditable_id).
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
