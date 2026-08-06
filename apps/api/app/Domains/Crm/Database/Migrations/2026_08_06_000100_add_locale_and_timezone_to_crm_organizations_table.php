<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_organizations', function (Blueprint $table) {
            // Organization default locale (allowlisted) and IANA timezone. Both nullable so existing
            // rows keep working and fall back to the application defaults.
            $table->string('locale', 8)->nullable()->after('website');
            $table->string('timezone', 64)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('crm_organizations', function (Blueprint $table) {
            $table->dropColumn(['locale', 'timezone']);
        });
    }
};
