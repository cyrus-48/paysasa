<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable log of every inbound webhook/callback received, BEFORE and
 * independent of whether it was successfully processed. Storing rows even
 * for signature-verification failures (WebhookStatus::VerificationFailed)
 * is deliberate: it's the forensic trail for replay-attack or spoofing
 * investigation, and `signature` + `provider` + `provider_event_id` form
 * the dedupe key that makes callback processing idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();

            $table->string('provider');
            $table->string('event_type')->nullable(); // provider-specific event name, e.g. 'charge.success'
            $table->string('provider_event_id')->nullable(); // provider's own idempotency/event id, when supplied

            $table->string('status')->default('received')->index(); // WebhookStatus enum value
            $table->string('signature')->nullable();
            $table->boolean('signature_valid')->nullable();

            $table->json('headers')->nullable();
            $table->json('payload');

            $table->ipAddress('ip_address')->nullable();
            $table->string('error_message')->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);
            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
