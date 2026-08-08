<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course publishing lifecycle columns:
 *  - scheduled_publish_at: when a Scheduled course should auto-publish (consumed on publish).
 *  - last_published_at: stamped on every publish (published_at keeps only the first-publish time).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->timestamp('scheduled_publish_at')->nullable()->after('published_at');
            $table->timestamp('last_published_at')->nullable()->after('scheduled_publish_at');

            // The scheduler scans for due Scheduled courses every minute.
            $table->index(['status', 'scheduled_publish_at']);
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_publish_at']);
            $table->dropColumn(['scheduled_publish_at', 'last_published_at']);
        });
    }
};
