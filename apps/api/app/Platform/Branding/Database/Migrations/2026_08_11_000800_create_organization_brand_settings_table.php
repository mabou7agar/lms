<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization white-label OVERRIDE of the global brand_settings singleton. One row per org
 * (organization_id UNIQUE); a NULL-org row does NOT live here — the GLOBAL default stays the single
 * brand_settings row, untouched. Each JSON group mirrors brand_settings so an org row can override
 * any subset of identity/logos/theme (and, for completeness, email/certificate), and the resolver
 * deep-merges the org's DEFINED values OVER the global defaults-merged payload — an org overrides
 * only the fields it set and inherits everything else from the global brand.
 *
 * organization_id is an indexed opaque unsigned bigint (NOT a cross-context FK) — mirroring how
 * users.organization_id, media_assets.organization_id and sso_domain_mappings.organization_id keep
 * the owning module decoupled from CRM's organizations table. Presentation-only; nothing sensitive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_brand_settings', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            // Exactly one override row per organization (opaque id, one org can never have two brands).
            $table->unsignedBigInteger('organization_id')->unique();
            $table->json('identity')->nullable();     // brand name / company / support / social / locale overrides
            $table->json('logos')->nullable();        // logo / favicon / icons overrides (media refs or paths)
            $table->json('theme')->nullable();        // primary/secondary colours + safe theme token overrides
            $table->json('email')->nullable();        // email branding overrides
            $table->json('certificate')->nullable();  // certificate branding overrides
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_brand_settings');
    }
};
