<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_sections', function (Blueprint $table) {
            $table->jsonb('title_i18n')->nullable()->after('title');
            $table->jsonb('summary_i18n')->nullable()->after('summary');
        });

        DB::statement("UPDATE course_sections SET title_i18n = jsonb_build_object('en', title) WHERE title IS NOT NULL");
        DB::statement("UPDATE course_sections SET summary_i18n = jsonb_build_object('en', summary) WHERE summary IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('course_sections', function (Blueprint $table) {
            $table->dropColumn(['title_i18n', 'summary_i18n']);
        });
    }
};
