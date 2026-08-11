<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordered steps of a campaign. `position` is the 1-based sequence; `delay_minutes` is the offset from
 * the PREVIOUS step (position 1 typically 0 = send on enrollment). Each step renders `template_key`
 * on `channel` under the marketing category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_steps', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('marketing_campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->string('template_key');
            $table->string('channel', 16)->default('email');
            $table->timestamps();

            $table->unique(['marketing_campaign_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_steps');
    }
};
