<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give categories a dedicated ICON reference, distinct from `image_path`. Stores a small icon
 * identifier/name (e.g. a Lucide/heroicon key), NOT a media reference — the admin picks an icon for
 * compact nav/menu rendering while `image_path` keeps the full media-picker image. Nullable,
 * forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('icon')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('icon');
        });
    }
};
