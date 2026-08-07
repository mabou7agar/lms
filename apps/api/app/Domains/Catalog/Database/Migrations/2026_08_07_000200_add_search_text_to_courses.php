<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Denormalised, locale-folded search index (Sprint 0.5 — Arabic-aware search). Internal only:
        // never exposed through an API resource. Nullable so existing rows are valid immediately; the
        // authoritative value is (re)computed by the SearchableText trait on every model write.
        Schema::table('courses', function (Blueprint $table): void {
            $table->text('search_text')->nullable()->after('description');
        });

        // Best-effort backfill for pre-existing rows so they are searchable without a re-save. This
        // lowercases and concatenates the legacy scalars and the en/ar locales of each i18n map; full
        // Arabic letter/diacritic folding is applied by the trait the next time a row is saved.
        DB::statement(<<<'SQL'
            UPDATE courses SET search_text = lower(trim(
                concat_ws(' ',
                    coalesce(title, ''),
                    coalesce(subtitle, ''),
                    coalesce(description, ''),
                    coalesce(title_i18n->>'en', ''),
                    coalesce(title_i18n->>'ar', ''),
                    coalesce(subtitle_i18n->>'en', ''),
                    coalesce(subtitle_i18n->>'ar', ''),
                    coalesce(description_i18n->>'en', ''),
                    coalesce(description_i18n->>'ar', '')
                )
            ))
        SQL);
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('search_text');
        });
    }
};
