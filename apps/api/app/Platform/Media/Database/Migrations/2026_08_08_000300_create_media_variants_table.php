<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A / D6 - Derived image variants for a media asset. One row per (asset, variant key). Each row
 * points at a NEW storage object (storage_key) produced by the deterministic GD pipeline; the original
 * media_assets object is never mutated or referenced here beyond the FK.
 *
 * Boundary rules (PostgreSQL, the app/test driver):
 *  - media_asset_id is an INTRA-context FK (both tables are Media's own) — safe to constrain + cascade.
 *  - unique (media_asset_id, variant_key) makes re-running the pipeline an idempotent upsert: the same
 *    input + params overwrite the same variant object and row rather than duplicating.
 *  - created_at only (variants are immutable derivations — there is no update path), so the model pins
 *    UPDATED_AT = null.
 *
 * TENANCY NOTE (T1, later phase): a variant belongs to exactly one asset, so it inherits that asset's
 * (future) organization_id transitively — no direct tenant column is added here. Any later query that
 * lists variants directly MUST scope through media_assets (join or whereHas) once org scoping exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_variants', function (Blueprint $table): void {
            $table->id();

            // Intra-context FK — safe to constrain within Media's own tables; cascades on asset delete.
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();

            // Variant key within a surface set: thumbnail | small | medium | large | ... (<= 32 chars).
            $table->string('variant_key', 32);

            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('format', 8); // webp | jpeg | png | avif

            // The NEW derived object's key on the images disk (never the original's key).
            $table->string('storage_key');
            $table->unsignedBigInteger('size_bytes');

            // Immutable derivation: created_at only (no updated_at).
            $table->timestamp('created_at')->nullable();

            $table->unique(['media_asset_id', 'variant_key']);
            $table->index('media_asset_id');
        });

        DB::statement(
            'ALTER TABLE media_variants ADD CONSTRAINT media_variants_dimensions_check '
            .'CHECK (width > 0 AND height > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('media_variants');
    }
};
