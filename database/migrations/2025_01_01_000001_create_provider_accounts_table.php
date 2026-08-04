<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-merchant, per-provider credential and configuration overrides.
 * Enables Multi-Merchant support: a marketplace app can onboard many
 * sub-merchants, each with their own M-Pesa shortcode or Stripe account,
 * without deploying config changes. Rows here take precedence over
 * config/paysasa.php when a `merchant_id` is supplied to the fluent API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // stable external identifier, safe to expose in APIs

            $table->string('merchant_id')->index(); // FK to the host app's tenant/merchant table (no hard FK: host app owns that table)
            $table->string('provider'); // enum PaymentProvider value, e.g. 'mpesa'
            $table->string('label')->nullable(); // human-readable name, e.g. "Nairobi Branch Till"

            $table->string('environment')->default('sandbox'); // 'sandbox' | 'production'
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false); // default account for this merchant+provider pair

            $table->text('credentials'); // Laravel encrypted cast (JSON): API keys/secrets, never stored plaintext
            $table->json('settings')->nullable(); // non-secret overrides: shortcode, callback overrides, etc.

            $table->timestamp('credentials_rotated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'provider', 'label']);
            $table->index(['merchant_id', 'provider', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_accounts');
    }
};
