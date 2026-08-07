<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 / D1 - Opt an asset into an organizational folder. Forward-only and backward compatible:
 * the column is NULLABLE (every existing asset stays at the "root", folder_id = null) and carries a
 * nullOnDelete FK to media_folders so removing a folder never deletes its assets — they simply fall
 * back to root. The folder service also reassigns explicitly; this FK is the durable backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->unsignedBigInteger('folder_id')->nullable()->after('course_id');
            $table->foreign('folder_id')->references('id')->on('media_folders')->nullOnDelete();
            $table->index('folder_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropForeign(['folder_id']);
            $table->dropIndex(['folder_id']);
            $table->dropColumn('folder_id');
        });
    }
};
