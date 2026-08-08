<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T1 — Analytics tenant dimension (forward-only, nullable).
 *
 * Adds a nullable `organization_id` to the metric_snapshots READ MODEL:
 *   - NULL  = a GLOBAL / platform metric (every existing row is left NULL by this expand, so the
 *             1174 backward-compat tests keep reading exactly what they read before);
 *   - non-null = an ORGANIZATION-specific metric bucket.
 *
 * The old idempotency key (metric_key, granularity, period, dimension_key, dimension_value) merged
 * all tenants into one bucket. To keep org1 and org2 activity in DISTINCT buckets — while preserving
 * today's single-bucket guarantee for global rows — the single unique index is replaced with TWO
 * partial unique indexes (Postgres NULLs are DISTINCT, so a single index over a nullable column would
 * silently stop enforcing uniqueness on global rows):
 *   - global rows (organization_id IS NULL): unique on the original 5 columns — identical to before;
 *   - org rows   (organization_id IS NOT NULL): unique on organization_id + the original 5 columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metric_snapshots', function (Blueprint $table): void {
            // Nullable: existing rows stay NULL = global. The tenant is stamped on create only when a
            // tenant is resolved (BelongsToTenantNullable) — never from client input.
            $table->unsignedBigInteger('organization_id')->nullable()->after('id');

            // Read-path index for tenant-filtered metric/period scans (KpiEngine total()/series()).
            $table->index(['organization_id', 'metric_key', 'period'], 'metric_snapshots_org_metric_period');
        });

        // Swap the tenant-agnostic unique for tenant-aware partial uniques.
        Schema::table('metric_snapshots', function (Blueprint $table): void {
            $table->dropUnique('metric_snapshots_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX metric_snapshots_unique_global ON metric_snapshots '
            .'(metric_key, granularity, period, dimension_key, dimension_value) '
            .'WHERE organization_id IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX metric_snapshots_unique_org ON metric_snapshots '
            .'(organization_id, metric_key, granularity, period, dimension_key, dimension_value) '
            .'WHERE organization_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS metric_snapshots_unique_org');
        DB::statement('DROP INDEX IF EXISTS metric_snapshots_unique_global');

        Schema::table('metric_snapshots', function (Blueprint $table): void {
            $table->unique(
                ['metric_key', 'granularity', 'period', 'dimension_key', 'dimension_value'],
                'metric_snapshots_unique'
            );
        });

        Schema::table('metric_snapshots', function (Blueprint $table): void {
            $table->dropIndex('metric_snapshots_org_metric_period');
            $table->dropColumn('organization_id');
        });
    }
};
