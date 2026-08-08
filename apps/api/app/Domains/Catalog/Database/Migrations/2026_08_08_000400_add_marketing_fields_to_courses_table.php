<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course marketing fields (Tranche 3c):
 *  - learning_objectives_i18n / requirements_i18n / target_audience_i18n: localized LISTS stored as
 *    a {locale => [items...]} JSONB map (same i18n shape as title_i18n, but the leaf is an array of
 *    strings). Resolved to the request locale by the public resource — the raw map never leaks.
 *  - duration_minutes: a manual/override total course duration in minutes.
 *  - trailer_path: a promo video reference, mirroring thumbnail_path exactly (a MediaAsset public_id
 *    picked via the MediaPicker, or a legacy URL) — resolved to a playback-safe URL like the thumbnail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->jsonb('learning_objectives_i18n')->nullable()->after('description_i18n');
            $table->jsonb('requirements_i18n')->nullable()->after('learning_objectives_i18n');
            $table->jsonb('target_audience_i18n')->nullable()->after('requirements_i18n');
            $table->unsignedInteger('duration_minutes')->nullable()->after('target_audience_i18n');
            $table->string('trailer_path')->nullable()->after('thumbnail_path');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn([
                'learning_objectives_i18n',
                'requirements_i18n',
                'target_audience_i18n',
                'duration_minutes',
                'trailer_path',
            ]);
        });
    }
};
