<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2/W02A hardening - enforce block ordering + backfill idempotency at the database layer.
 *
 * Replaces the plain (lesson_id, position) index on content_blocks with a UNIQUE index over LIVE
 * (non-soft-deleted) rows. This guarantees two blocks cannot share a position within a lesson and
 * that a concurrent or repeated backfill cannot create duplicate position-0 seed blocks, while still
 * allowing a lesson to hold many blocks (at distinct positions) and allowing soft-deleted rows to
 * coexist with a live replacement. Additive; no data is modified.
 *
 * Partial-unique-index syntax is supported by PostgreSQL (the app/test driver) and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_blocks', function (Blueprint $table): void {
            $table->dropIndex(['lesson_id', 'position']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX content_blocks_lesson_position_live_unique '
            .'ON content_blocks (lesson_id, position) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS content_blocks_lesson_position_live_unique');

        Schema::table('content_blocks', function (Blueprint $table): void {
            $table->index(['lesson_id', 'position']);
        });
    }
};
