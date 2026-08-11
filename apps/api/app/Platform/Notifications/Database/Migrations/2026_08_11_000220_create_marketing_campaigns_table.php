<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A marketing campaign: an ordered drip of steps a recipient is enrolled into. Tenant-scoped
 * (organization_id nullable = platform-level marketing, indexed, no FK). Targets marketing audiences
 * (leads/contacts), never transactional user flows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('name');
            $table->string('status', 16)->default('draft');
            // Audience source + allow-listed filter used when enrolling a whole segment.
            $table->string('audience_type', 24)->default('lead');
            $table->json('audience_filter')->nullable();
            $table->timestamps();

            $table->index('organization_id');
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaigns');
    }
};
