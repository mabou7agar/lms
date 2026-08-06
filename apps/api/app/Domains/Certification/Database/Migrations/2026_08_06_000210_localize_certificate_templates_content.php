<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->jsonb('name_i18n')->nullable()->after('name');
            $table->jsonb('html_i18n')->nullable()->after('html');
        });

        DB::statement("UPDATE certificate_templates SET name_i18n = jsonb_build_object('en', name) WHERE name IS NOT NULL");
        DB::statement("UPDATE certificate_templates SET html_i18n = jsonb_build_object('en', html) WHERE html IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->dropColumn(['name_i18n', 'html_i18n']);
        });
    }
};
