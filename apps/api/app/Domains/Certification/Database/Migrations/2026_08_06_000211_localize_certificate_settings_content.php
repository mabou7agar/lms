<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_settings', function (Blueprint $table) {
            $table->jsonb('issuer_name_i18n')->nullable()->after('issuer_name');
            $table->jsonb('signature_name_i18n')->nullable()->after('signature_name');
            $table->jsonb('signature_title_i18n')->nullable()->after('signature_title');
        });

        DB::statement("UPDATE certificate_settings SET issuer_name_i18n = jsonb_build_object('en', issuer_name) WHERE issuer_name IS NOT NULL");
        DB::statement("UPDATE certificate_settings SET signature_name_i18n = jsonb_build_object('en', signature_name) WHERE signature_name IS NOT NULL");
        DB::statement("UPDATE certificate_settings SET signature_title_i18n = jsonb_build_object('en', signature_title) WHERE signature_title IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('certificate_settings', function (Blueprint $table) {
            $table->dropColumn(['issuer_name_i18n', 'signature_name_i18n', 'signature_title_i18n']);
        });
    }
};
