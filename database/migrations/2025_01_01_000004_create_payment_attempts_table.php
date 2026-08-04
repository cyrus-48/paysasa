<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records EVERY attempt to initiate a charge for a payment, including
 * failed/timed-out ones that never became a `transactions` row (e.g. an
 * STK push the customer cancelled, or a request that timed out before the
 * provider acknowledged it). Distinct from `transactions`, which only
 * logs legs the provider actually acknowledged. Used to drive retry
 * policy and to detect brute-force / velocity abuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();

            $table->unsignedSmallInteger('attempt_number');
            $table->string('status'); // PaymentStatus enum value
            $table->string('provider_reference')->nullable();

            $table->string('error_code')->nullable();
            $table->string('error_message')->nullable();

            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent')->nullable();

            $table->json('raw_response')->nullable();

            $table->timestamps();

            $table->index(['payment_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
