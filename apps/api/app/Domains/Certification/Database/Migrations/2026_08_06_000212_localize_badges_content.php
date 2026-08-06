<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->jsonb('name_i18n')->nullable()->after('name');
            $table->jsonb('description_i18n')->nullable()->after('description');
            $table->jsonb('criteria_i18n')->nullable()->after('criteria');
        });

        DB::statement("UPDATE badges SET name_i18n = jsonb_build_object('en', name) WHERE name IS NOT NULL");
        DB::statement("UPDATE badges SET description_i18n = jsonb_build_object('en', description) WHERE description IS NOT NULL");
        DB::statement("UPDATE badges SET criteria_i18n = jsonb_build_object('en', criteria) WHERE criteria IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->dropColumn(['name_i18n', 'description_i18n', 'criteria_i18n']);
        });
    }
};
