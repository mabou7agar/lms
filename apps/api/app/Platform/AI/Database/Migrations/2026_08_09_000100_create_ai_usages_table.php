<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only cost/usage ledger for every AI call. One row per provider call, written by
     * AiUsageRecorder. Tenant-owned via organization_id (nullable for platform/system calls). The
     * exact prompt key + version used is captured immutably here so a run's prompt can never be
     * reconstructed wrongly after the prompt is later edited or rolled back.
     */
    public function up(): void
    {
        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('feature', 16);            // tutor | copilot | analytics | search | embedding | other
            $table->string('provider', 24);           // fake | openai | anthropic | gemini | openrouter | ollama
            $table->string('model', 120);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('estimated_cost_micros')->default(0); // USD micros (1e-6 USD)
            $table->string('request_id', 64);
            $table->string('prompt_key')->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->timestamp('created_at')->nullable();

            // Aggregation paths: per-org-month, per-user-day, global-month, per-feature.
            $table->index(['organization_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('feature');
            $table->index('created_at');
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usages');
    }
};
