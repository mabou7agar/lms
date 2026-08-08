<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1 - Public media resolution. Adds the asset VISIBILITY dimension used to decide how a stored
 * MediaAsset reference resolves in a public renderer (public stable URL / signed URL / hidden).
 *
 * SECURE BY DEFAULT (forward-only): the column defaults to 'private', so every EXISTING row (added
 * before this column existed) and every future row is PRIVATE unless an authorized actor raises it
 * through MediaVisibilityService. Visibility is NEVER inferred from a storage path, so nothing here
 * backfills any row to a wider value.
 *
 * NOTE (boundary): this migration touches ONLY the `visibility` column. The thumbnail_ref / variants
 * concern on media_assets is owned by a different workstream and is intentionally left untouched.
 *
 * TENANCY NOTE (T1, later phase): once organization scoping exists, public/signed resolution must be
 * tenant-scoped; no schema change is needed here for that, but the owning org id already lives on the
 * asset's cross-context columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            // 16 chars is ample for 'authenticated'; default keeps existing rows secure.
            $table->string('visibility', 16)->default('private');
            $table->index('visibility');
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropIndex(['visibility']);
            $table->dropColumn('visibility');
        });
    }
};
