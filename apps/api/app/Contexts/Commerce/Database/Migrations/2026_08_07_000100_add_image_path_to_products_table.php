<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U6 - Give products an image reference so the admin can pick or upload one via the media picker.
 * Stores the MediaPicker string value (a MediaAsset public_id, or a pre-existing URL/path preserved
 * by the picker's dual-read). Forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
