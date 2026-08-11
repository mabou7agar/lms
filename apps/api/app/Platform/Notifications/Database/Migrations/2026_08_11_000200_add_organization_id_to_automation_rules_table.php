<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scopes the automation substrate. Each automation rule is now owned by a tenant
 * (organization_id, nullable = platform-level, indexed, NO foreign key — the standard opaque tenant
 * column). The AutomationRunner evaluates ONLY the acting tenant's rules, so org A's rules can never
 * fire on org B's events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable()->after('id');
            $table->index(['organization_id', 'trigger_type', 'trigger_key'], 'automation_rules_tenant_trigger_idx');
        });
    }

    public function down(): void
    {
        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->dropIndex('automation_rules_tenant_trigger_idx');
            $table->dropColumn('organization_id');
        });
    }
};
