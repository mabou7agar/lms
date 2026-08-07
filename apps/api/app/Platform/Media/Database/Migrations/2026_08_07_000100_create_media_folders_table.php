<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 / D1 - Optional organizational folders/collections for the DAM. Forward-only and fully
 * backward compatible: nothing else references this table until an asset opts in via the (also
 * nullable) media_assets.folder_id column added in the sibling migration.
 *
 * Boundary rules (mirroring media_assets):
 *  - parent_id is an OPTIONAL self-reference for nesting; nullOnDelete so removing a parent never
 *    cascade-deletes a subtree (the folder service reparents children explicitly).
 *  - created_by is a CROSS-CONTEXT scalar (Identity user) — indexed, NOT foreign-keyed.
 *  - owner_id is a nullable, indexed tenant/owner scope column reserved for future tenant-readiness
 *    (T1). NO tenancy logic is attached to it here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table): void {
            $table->id();
            $table->publicId();

            $table->string('name');

            // Optional nesting — self reference, never cascades a delete.
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->foreign('parent_id')->references('id')->on('media_folders')->nullOnDelete();

            // Cross-context scalar ref — indexed, never foreign-keyed (DDD boundary).
            $table->unsignedBigInteger('created_by');

            // Reserved tenant/owner scope for future tenant-readiness (no logic yet).
            $table->unsignedBigInteger('owner_id')->nullable();

            $table->timestamps();

            $table->index('parent_id');
            $table->index('created_by');
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_folders');
    }
};
