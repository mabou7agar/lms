<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            // T1 Option-N ("global-OR-own-org") tenancy. The T1 matrix classifies `categories` as
            // SHARED-OR-OWNED/NULLABLE (global catalog taxonomy by default, optional org-private
            // categories when non-null). NULL = GLOBAL (every existing row); non-null = a single
            // organization's private category. Forward-only, no NOT NULL, NO backfill — see the courses
            // migration for the full rationale (indexed opaque id, no cross-domain FK).
            $table->unsignedBigInteger('organization_id')->nullable()->after('id');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropIndex(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
