<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C5 - Promote the dormant content_blocks table into a fully operable, ordered block layer under a
 * lesson. Forward-only and strictly ADDITIVE: no existing column is modified and no data is
 * destroyed. The legacy `payload` column is KEPT untouched (backfill + snapshot readers still use
 * it); the new localized surface lives beside it.
 *
 * New columns on content_blocks:
 *  - content_i18n : localized, typed-per-BlockType payload map ({ en:{...}, ar:{...} }). The new
 *                   editing surface (HasTranslations). Never an arbitrary blob — validated per type.
 *  - config       : block configuration (presentation/behaviour) distinct from content.
 *  - lock_version : optimistic-concurrency counter mirroring the curriculum C3 pattern on
 *                   sections/lessons. Non-null, defaults to 0, so existing rows and version-omitting
 *                   callers are unaffected (last-write-wins) exactly as C3 established.
 *  - created_by   : authoring attribution (users.id). Nullable, no FK — Identity lives in another
 *                   context; the reference is intentionally decoupled.
 *
 * TENANCY NOTE (later phase): content_blocks has no tenant/organization column yet. When tenant
 * scoping lands, this table needs an organization_id (derivable via lesson -> section -> course) and
 * the (lesson_id, position) uniqueness + every block query must become tenant-aware. Deferred here
 * by instruction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_blocks', function (Blueprint $table): void {
            $table->jsonb('content_i18n')->nullable()->after('payload');
            $table->jsonb('config')->nullable()->after('content_i18n');
            $table->unsignedInteger('lock_version')->default(0)->after('publish_state');
            $table->unsignedBigInteger('created_by')->nullable()->after('learning_object_id');
            $table->index('created_by');
        });

        // Seed the new localized surface from the legacy payload for any block that already exists
        // (e.g. rows created by BlockBackfillService), under the default authoring locale. Deterministic
        // and non-destructive: `payload` is left in place as the backward-compatible mirror.
        DB::statement(
            "UPDATE content_blocks SET content_i18n = jsonb_build_object('en', payload) "
            .'WHERE payload IS NOT NULL AND content_i18n IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('content_blocks', function (Blueprint $table): void {
            $table->dropIndex(['created_by']);
            $table->dropColumn(['content_i18n', 'config', 'lock_version', 'created_by']);
        });
    }
};
