<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_questions', function (Blueprint $table) {
            $table->jsonb('prompt_i18n')->nullable()->after('prompt');
            $table->jsonb('explanation_i18n')->nullable()->after('explanation');
            $table->jsonb('hint_i18n')->nullable()->after('hint');
        });

        DB::statement("UPDATE assessment_questions SET prompt_i18n = jsonb_build_object('en', prompt) WHERE prompt IS NOT NULL");
        DB::statement("UPDATE assessment_questions SET explanation_i18n = jsonb_build_object('en', explanation) WHERE explanation IS NOT NULL");
        DB::statement("UPDATE assessment_questions SET hint_i18n = jsonb_build_object('en', hint) WHERE hint IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('assessment_questions', function (Blueprint $table) {
            $table->dropColumn(['prompt_i18n', 'explanation_i18n', 'hint_i18n']);
        });
    }
};
