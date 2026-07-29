<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3 (C3) — deterministic notification deduplication.
 *
 * A notification's identity is (recipient + template + payload), or a caller-supplied event key.
 * Storing that as a UNIQUE `dedup_key` makes re-dispatching the same domain event a no-op instead
 * of a second notification + second send.
 *
 * Safe on a populated database: the column is added nullable, so every existing row gets NULL, and
 * PostgreSQL treats NULLs as DISTINCT in a unique index — no collision, no backfill. Only new rows
 * carry a non-null deterministic key, and two rows with the same key is exactly the duplicate we
 * want the index to reject.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('dedup_key')->nullable()->after('data');
            $table->unique('dedup_key');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique(['dedup_key']);
            $table->dropColumn('dedup_key');
        });
    }
};
