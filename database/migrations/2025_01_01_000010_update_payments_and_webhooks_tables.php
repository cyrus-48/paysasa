<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->string('dedupe_key')->nullable()->after('provider_event_id');
            $table->unsignedSmallInteger('match_attempts')->default(0)->after('error_message');

            $table->unique(['provider', 'dedupe_key']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('last_checked_at')->nullable()->after('completed_at');
            $table->unsignedSmallInteger('check_count')->default(0)->after('last_checked_at');
            $table->boolean('outcome_uncertain')->default(false)->after('check_count');
            $table->json('flags')->nullable()->after('outcome_uncertain');

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['last_checked_at', 'check_count', 'outcome_uncertain', 'flags']);
        });

        Schema::table('webhooks', function (Blueprint $table) {
            $table->dropUnique(['provider', 'dedupe_key']);
            $table->dropColumn(['dedupe_key', 'match_attempts']);
        });
    }
};
