<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->jsonb('title_i18n')->nullable()->after('title');
        });

        DB::statement("UPDATE assignments SET title_i18n = jsonb_build_object('en', title) WHERE title IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn(['title_i18n']);
        });
    }
};
