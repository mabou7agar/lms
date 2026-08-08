<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            // T1 Option-N ("global-OR-own-org") tenancy for the catalog root. NULLABLE by design:
            //   NULL       = GLOBAL/public platform catalog content (every existing row stays this way),
            //   non-null   = a single organization's PRIVATE course.
            // Forward-only, no NOT NULL, NO backfill — existing rows remain NULL (global) so the public
            // catalog and the existing suite are behaviourally unchanged. SharedOrOwnedTenantScope reads
            // this column to filter (org_id IS NULL OR org_id = active tenant).
            //
            // Deliberately an indexed opaque id, NOT a database foreign key: Catalog stays decoupled from
            // the CRM organizations table (a cross-domain FK would couple Catalog's migration to CRM's),
            // matching how Identity's users.organization_id and the tenancy kernel treat the tenant id as
            // an opaque value.
            $table->unsignedBigInteger('organization_id')->nullable()->after('id');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropIndex(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
