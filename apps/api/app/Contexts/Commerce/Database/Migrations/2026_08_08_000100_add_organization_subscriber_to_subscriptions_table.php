<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give a subscription a SUBSCRIBER that is either an individual user (the existing shape) OR a CRM
 * organization. Additive and forward-only: user_id becomes nullable so an organization subscription
 * can leave it unset, and a nullable organization_id / seats / seat_pool_id are added.
 *
 * Domain invariant (enforced in the Actions layer, not by a cross-DB CHECK): exactly one of
 * {user_id, organization_id} is set on any row. Individual (user_id) subscriptions keep behaving
 * exactly as before — every existing row already has user_id set and organization_id null.
 *
 * TENANCY (T1, later): organization_id and seat_pool_id are tenant-owned foreign keys. When tenant
 * scoping lands, subscriptions carrying an organization_id (and all queries that filter on it or join
 * through seat_pool_id → seat_pools → crm_organizations) must be constrained to the active tenant.
 * Individual user subscriptions are scoped by their existing owner relationship instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // An organization subscription leaves user_id null; individual subscriptions keep it set.
            $table->foreignId('user_id')->nullable()->change();

            // The organization subscriber. Nullable FK to the CRM corporate account.
            // TENANCY (T1): tenant-owned — later queries filtering on this must be tenant-scoped.
            $table->foreignId('organization_id')
                ->nullable()
                ->after('user_id')
                ->constrained('crm_organizations')
                ->cascadeOnDelete();

            // Seat capacity purchased for an organization subscription (null for user subscriptions).
            // Capacity only: the recurring charge stays the plan's price so the shared renewal /
            // upgrade / proration logic is reused unchanged.
            $table->unsignedInteger('seats')->nullable()->after('amount_minor');

            // The provisioned CRM seat pool this organization subscription is bound to.
            // TENANCY (T1): tenant-owned via seat_pools.organization_id — scope later.
            $table->foreignId('seat_pool_id')
                ->nullable()
                ->after('seats')
                ->constrained('seat_pools')
                ->nullOnDelete();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropConstrainedForeignId('seat_pool_id');
            $table->dropColumn('seats');
            $table->dropConstrainedForeignId('organization_id');
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
