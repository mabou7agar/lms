<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom (white-label) hosts an organization can serve its branded LMS from. The host is GLOBALLY
 * UNIQUE (one org per host) so an incoming Host header can never resolve to two tenants. A host only
 * resolves to its org's brand once VERIFIED (verified_at IS NOT NULL); until then the brand resolver
 * falls back to the global brand. Verification is a super_admin-toggled stub — NO DNS/ACME is built.
 *
 * organization_id / created_by are indexed opaque unsigned bigints (NOT cross-context FKs), matching
 * the tenancy convention used across users / media_assets / sso_domain_mappings so Branding stays
 * decoupled from CRM's organizations table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_domains', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->unsignedBigInteger('organization_id');
            // Globally unique host (lowercased by the FormRequest); one organization per host.
            $table->string('host')->unique();
            $table->boolean('is_primary')->default(false);
            // Verification is a manual super_admin stub flag; a host resolves only when this is set.
            $table->timestamp('verified_at')->nullable();
            // Opaque token an org would place in DNS/well-known for a future real verification flow.
            $table->string('verification_token', 64);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_domains');
    }
};
