<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_question_options', function (Blueprint $table) {
            $table->jsonb('label_i18n')->nullable()->after('label');
            $table->jsonb('feedback_i18n')->nullable()->after('feedback');
        });

        DB::statement("UPDATE assessment_question_options SET label_i18n = jsonb_build_object('en', label) WHERE label IS NOT NULL");
        DB::statement("UPDATE assessment_question_options SET feedback_i18n = jsonb_build_object('en', feedback) WHERE feedback IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('assessment_question_options', function (Blueprint $table) {
            $table->dropColumn(['label_i18n', 'feedback_i18n']);
        });
    }
};
