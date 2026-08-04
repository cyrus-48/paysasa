<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();

            $table->unsignedBigInteger('amount_minor'); // may be < payments.amount_minor for a partial refund
            $table->char('currency', 3)->default('KES');
            $table->string('status')->default('pending')->index(); // RefundStatus enum value
            $table->string('reason')->nullable();

            $table->string('provider_reference')->nullable();
            $table->json('raw_response')->nullable();

            // Who requested it — host app's user id, no hard FK (host app owns the users table).
            $table->string('initiated_by')->nullable();

            $table->timestamps();

            $table->index(['payment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
