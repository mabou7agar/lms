<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitation lifecycle: a single-use opaque token (+ optional expiry) lets an invited employee accept
 * or decline their membership. Additive and nullable so existing members are unaffected. The token is
 * unique so a lookup by token resolves at most one membership.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_members', function (Blueprint $table): void {
            $table->string('invitation_token', 64)->nullable()->unique()->after('status');
            $table->timestamp('invitation_expires_at')->nullable()->after('invitation_token');
        });
    }

    public function down(): void
    {
        Schema::table('organization_members', function (Blueprint $table): void {
            $table->dropUnique(['invitation_token']);
            $table->dropColumn(['invitation_token', 'invitation_expires_at']);
        });
    }
};
