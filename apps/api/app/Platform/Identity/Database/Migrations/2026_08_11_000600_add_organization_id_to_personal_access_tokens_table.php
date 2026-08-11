<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-tag developer API keys. A developer API key (a Sanctum personal access token issued
     * through the public-API key-management endpoints) carries the issuing org-admin's
     * organization id so listing/revocation stay tenant-scoped: an org-admin sees and manages ONLY
     * their own organization's keys.
     *
     * Nullable + additive: existing tokens (interactive login, device, social) leave this null and
     * are unaffected — a null organization_id simply means "not a developer API key". Deliberately
     * an indexed opaque id, not a database foreign key, matching how the users table stores
     * organization_id (Identity stays decoupled from the CRM organizations table).
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable()->after('abilities');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
