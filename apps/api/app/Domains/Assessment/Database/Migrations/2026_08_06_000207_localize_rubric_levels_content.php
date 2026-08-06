<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rubric_levels', function (Blueprint $table) {
            $table->jsonb('title_i18n')->nullable()->after('title');
            $table->jsonb('description_i18n')->nullable()->after('description');
        });

        DB::statement("UPDATE rubric_levels SET title_i18n = jsonb_build_object('en', title) WHERE title IS NOT NULL");
        DB::statement("UPDATE rubric_levels SET description_i18n = jsonb_build_object('en', description) WHERE description IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('rubric_levels', function (Blueprint $table) {
            $table->dropColumn(['title_i18n', 'description_i18n']);
        });
    }
};
