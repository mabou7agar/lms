<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user quiet-hours preference. A marketing message scheduled to land inside [start, end) in the
 * user's timezone (the existing `timezone` column) is DEFERRED to the window end, never dropped.
 * Transactional/critical messages ignore this entirely.
 *
 * Times are stored as "HH:MM" strings interpreted in the user's timezone. A window may wrap midnight
 * (e.g. 21:00 -> 08:00). Disabled by default so existing behaviour is unchanged until a user opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notification_settings', function (Blueprint $table): void {
            $table->boolean('quiet_hours_enabled')->default(false)->after('timezone');
            $table->string('quiet_hours_start', 5)->nullable()->after('quiet_hours_enabled');
            $table->string('quiet_hours_end', 5)->nullable()->after('quiet_hours_start');
        });
    }

    public function down(): void
    {
        Schema::table('user_notification_settings', function (Blueprint $table): void {
            $table->dropColumn(['quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end']);
        });
    }
};
