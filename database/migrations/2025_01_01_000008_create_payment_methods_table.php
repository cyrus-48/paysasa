<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved/tokenized payment methods (card-on-file, saved M-Pesa number) for
 * repeat/recurring charges. `token` is always the PROVIDER's vault token
 * (e.g. a Stripe PaymentMethod id) — this table never stores raw PANs,
 * CVVs, or other cardholder data, keeping the host application out of PCI
 * DSS SAQ D scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Polymorphic owner: whatever "customer" model the host app uses.
            $table->uuidMorphs('owner');

            $table->string('provider');
            $table->string('type'); // 'card' | 'mobile_money' | 'bank_account'

            $table->string('token'); // provider vault token/reference — never raw PAN
            $table->string('display_label')->nullable(); // e.g. "Visa •••• 4242" or "M-Pesa 07XX•••678"

            $table->string('brand')->nullable();        // card brand: visa, mastercard...
            $table->string('last_four', 4)->nullable();
            $table->unsignedTinyInteger('expiry_month')->nullable();
            $table->unsignedSmallInteger('expiry_year')->nullable();
            $table->string('phone')->nullable();         // mobile-money methods

            $table->boolean('is_default')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_type', 'owner_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
