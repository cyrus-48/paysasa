<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every discrete API leg executed against a provider for a given payment:
 * the initial charge, a subsequent capture, a payout leg of a split
 * payment, a reversal. A single `payments` row can have many
 * `transactions` — this is the ledger; `payments` is the summary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();

            $table->string('type'); // TransactionType enum value: charge|authorization|capture|refund|reversal|payout|transfer|...
            $table->string('status'); // PaymentStatus enum value at the time this leg completed
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('KES');

            $table->string('provider_reference')->nullable()->index();
            $table->json('raw_request')->nullable();  // outbound payload sent to the provider (secrets redacted)
            $table->json('raw_response')->nullable(); // inbound payload received from the provider

            $table->unsignedInteger('duration_ms')->nullable(); // provider round-trip latency, for SLA monitoring

            $table->timestamps();

            $table->index(['payment_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
