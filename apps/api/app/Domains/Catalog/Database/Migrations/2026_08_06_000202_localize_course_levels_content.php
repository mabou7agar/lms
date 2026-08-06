<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_levels', function (Blueprint $table) {
            $table->jsonb('name_i18n')->nullable()->after('name');
        });

        DB::statement("UPDATE course_levels SET name_i18n = jsonb_build_object('en', name) WHERE name IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('course_levels', function (Blueprint $table) {
            $table->dropColumn(['name_i18n']);
        });
    }
};
