<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured, queryable log of driver-level activity — every outbound API
 * call, retry, and notable state transition — kept separate from Laravel's
 * general log files so support/ops tooling can query "everything that
 * happened for payment X" with SQL instead of grepping log files. Not a
 * replacement for `transactions` (which is the settlement ledger); this is
 * the diagnostic trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();

            $table->string('provider')->nullable();
            $table->string('level')->default('info'); // debug|info|warning|error, mirrors PSR-3
            $table->string('message');
            $table->json('context')->nullable(); // redacted per config('paysasa.logging.redact_fields')

            $table->timestamps();

            $table->index(['payment_id', 'created_at']);
            $table->index(['level', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_logs');
    }
};
