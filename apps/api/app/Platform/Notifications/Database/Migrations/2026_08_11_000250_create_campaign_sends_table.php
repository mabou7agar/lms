<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-(enrollment, step) send ledger. The unique (enrollment, step) key makes each step's send
 * idempotent: a resumed/retried drip advance re-uses the existing row instead of sending twice.
 * `status` is the truthful outcome (sent / deferred for quiet hours / skipped for suppression or
 * missing consent); `deferred_until` records when a quiet-hours-deferred send should be retried.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_sends', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->foreignId('campaign_enrollment_id')->constrained('campaign_enrollments')->cascadeOnDelete();
            $table->foreignId('campaign_step_id')->constrained('campaign_steps')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('email');
            $table->string('status', 24);
            $table->string('reason')->nullable();
            $table->timestamp('deferred_until')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_enrollment_id', 'campaign_step_id'], 'campaign_sends_unique_step');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_sends');
    }
};
