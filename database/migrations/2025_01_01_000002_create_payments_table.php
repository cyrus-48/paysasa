<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The canonical record of merchant payment intent — one row per logical
 * payment regardless of how many provider API calls (attempts) it took to
 * settle. Everything else in the schema (transactions, attempts, refunds,
 * webhooks) hangs off `payments.id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // public-safe identifier; never expose the auto-increment id externally

            $table->foreignId('provider_account_id')->nullable()
                ->constrained('provider_accounts')->nullOnDelete();
            $table->string('merchant_id')->nullable()->index(); // multi-merchant partitioning key

            $table->string('provider'); // PaymentProvider enum value
            $table->string('category'); // PaymentCategory enum value, denormalized for fast filtering/reporting
            $table->string('type')->default('charge'); // TransactionType enum value
            $table->string('status')->default('pending')->index(); // PaymentStatus enum value

            $table->unsignedBigInteger('amount_minor'); // stored in minor units (cents) to avoid float rounding errors
            $table->char('currency', 3)->default('KES');

            $table->string('reference')->unique(); // merchant-supplied idempotent business reference, e.g. invoice number
            $table->string('provider_reference')->nullable()->index(); // provider's transaction/checkout id (CheckoutRequestID, PaymentIntent id...)
            $table->string('receipt_number')->nullable(); // final settlement receipt (e.g. MpesaReceiptNumber)

            $table->string('idempotency_key')->nullable()->unique();

            $table->string('customer_id')->nullable()->index(); // host app's user/customer id (no hard FK — host app owns that table)
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable()->index();

            // Optional polymorphic link to whatever the host app's payment is *for* (an Order, Invoice, Subscription...)
            $table->nullableUuidMorphs('payable');

            $table->string('description')->nullable();
            $table->json('metadata')->nullable(); // arbitrary caller-supplied context, e.g. {"student_id": 10, "invoice": 455}
            $table->json('splits')->nullable(); // marketplace split-payment recipients + amounts, if applicable

            $table->string('callback_url')->nullable();
            $table->string('failure_reason')->nullable();
            $table->string('failure_code')->nullable();

            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // STK push / checkout link expiry

            $table->timestamps();
            $table->softDeletes();

            $table->index(['provider', 'status']);
            $table->index(['merchant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
