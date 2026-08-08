<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $table): void {
            // T1 Option-N ("global-OR-own-org") tenancy for the Live session root. The T1 matrix models
            // Live sessions as SHARED-OR-OWNED/NULLABLE (public events are global; org-private cohorts are
            // optional) while registrations/attendance stay USER-OWNED (no tenant column here).
            // NULL = GLOBAL/public event (every existing row); non-null = a single organization's private
            // cohort. Forward-only, no NOT NULL, NO backfill; indexed opaque id, no cross-domain FK — see
            // the courses migration for the full rationale.
            $table->unsignedBigInteger('organization_id')->nullable()->after('id');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('live_sessions', function (Blueprint $table): void {
            $table->dropIndex(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
