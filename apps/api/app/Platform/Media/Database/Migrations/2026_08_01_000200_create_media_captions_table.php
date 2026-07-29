<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2/W04 - Caption / subtitle tracks for a media asset. Metadata only (an uploaded VTT/SRT
 * reference) — the platform never transcribes. One track per BCP-47 language per asset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_captions', function (Blueprint $table): void {
            $table->id();
            $table->publicId();

            // Intra-context FK — safe to constrain within Media's own tables.
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();

            $table->string('language', 35);   // BCP-47, e.g. en, en-US, zh-Hans-CN
            $table->string('label');
            $table->string('format', 8)->default('vtt'); // vtt | srt
            $table->string('storage_key')->nullable();
            $table->string('provider_ref')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('created_by');

            $table->timestamps();

            $table->unique(['media_asset_id', 'language']);
            $table->index('media_asset_id');
        });

        DB::statement(
            'ALTER TABLE media_captions ADD CONSTRAINT media_captions_format_check '
            ."CHECK (format IN ('vtt','srt'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('media_captions');
    }
};
