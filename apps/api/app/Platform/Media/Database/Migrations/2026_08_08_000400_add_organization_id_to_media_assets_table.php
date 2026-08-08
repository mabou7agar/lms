<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T1 Option-N ("global-OR-own-org") tenancy for the Media root (media_assets). Matrix ruling:
 * Media = SHARED-OR-OWNED / NULLABLE.
 *   NULL     = GLOBAL/public platform asset (marketing/catalog imagery) — every existing row stays
 *              this way, so the global catalog keeps rendering for everyone incl. anonymous,
 *   non-null = a single organization's PRIVATE asset (org course content / org branding).
 *
 * Forward-only, NULLABLE by design, NO NOT NULL and NO backfill: existing rows remain NULL (global)
 * so the P1 public-resolution suite and the whole existing suite are behaviourally unchanged.
 * SharedOrOwnedTenantScope (via BelongsToTenantNullable on MediaAsset) reads this column to filter
 * (organization_id IS NULL OR organization_id = active tenant); it no-ops when no tenant is resolved.
 *
 * Deliberately an indexed opaque unsigned bigint, NOT a database foreign key — mirroring the boundary
 * rules the table already documents for created_by/course_id and how Identity's users.organization_id
 * and the tenancy kernel treat the tenant id as an opaque value (Media stays decoupled from CRM's
 * organizations table). The children (media_variants, media_attachments, media_captions, media_folders)
 * intentionally get NO tenant column: each follows its asset/folder transitively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable()->after('course_id');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropIndex(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
