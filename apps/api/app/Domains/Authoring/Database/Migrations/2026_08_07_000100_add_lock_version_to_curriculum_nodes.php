<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C3 - Optimistic-locking counter for the curriculum nodes the visual builder edits.
 *
 * Forward-only and additive: a non-null integer `lock_version` defaulting to 0 is added to the
 * two node tables (sections + lessons). Existing rows and existing callers are unaffected — a
 * caller that omits `expected_version` never reads or compares the column, so the write path is
 * fully backward compatible. The column is intentionally NOT added to `courses`: course-scoped
 * reorders stay server-authoritative and are serialized by row locks, not an optimistic token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_sections', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(0)->after('publish_state');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(0)->after('is_preview');
        });
    }

    public function down(): void
    {
        Schema::table('course_sections', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });
    }
};
