<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Expand step of the Sprint 0.2 localization convention: add jsonb `{col}_i18n` maps beside
        // the legacy scalar (kept for the migrate -> contract window). `name` is a NOT NULL scalar
        // today, so it is backfilled into `name_i18n.en`; `description` never existed as a scalar, so
        // `description_i18n` is added as a localized-only nullable column with nothing to backfill.
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->jsonb('name_i18n')->nullable()->after('name');
            $table->jsonb('description_i18n')->nullable()->after('name_i18n');
        });

        DB::statement("UPDATE subscription_plans SET name_i18n = jsonb_build_object('en', name) WHERE name IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['name_i18n', 'description_i18n']);
        });
    }
};
