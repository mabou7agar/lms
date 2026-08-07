<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // The owning tenant (organization) of an employee account. Nullable: platform-direct
            // accounts (learners, staff without an org) carry none and remain un-scoped, so existing
            // behaviour is unchanged until an account is attached to an organization. RequestTenantResolver
            // reads this column to derive the active tenant for the authenticated user.
            //
            // Deliberately an indexed opaque id, NOT a database foreign key: the Identity schema stays
            // decoupled from the CRM organizations table (a cross-domain FK would couple Identity's
            // migration to CRM's), matching how tenancy treats the tenant id as an opaque value.
            $table->unsignedBigInteger('organization_id')->nullable()->after('is_active');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
