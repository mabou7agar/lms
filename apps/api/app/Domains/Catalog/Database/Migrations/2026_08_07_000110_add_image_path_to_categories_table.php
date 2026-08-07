<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U3 - Give categories an image/icon reference so the admin can pick or upload one via the media
 * picker. Stores the MediaPicker string value (a MediaAsset public_id, or a pre-existing URL/path
 * preserved by the picker's dual-read). Forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
