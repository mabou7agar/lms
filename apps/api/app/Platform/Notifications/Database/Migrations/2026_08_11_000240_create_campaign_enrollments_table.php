<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One recipient's progress through a campaign. `current_step` is the position ALREADY sent (0 = not
 * started); `next_run_at` is when the next step is due. The drip runner advances exactly one step per
 * due enrollment. A recipient snapshot (email/timezone/consent) is captured at enrollment so the send
 * path needs no cross-context read; consent is still re-checked live at send.
 *
 * The unique (campaign, recipient_type, recipient_id) makes enrolling the same recipient twice a
 * no-op — enrollment is idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->foreignId('marketing_campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->string('recipient_type', 24);
            $table->unsignedBigInteger('recipient_id');
            $table->string('email');
            $table->string('timezone', 64)->nullable();
            $table->string('locale', 8)->nullable();
            $table->boolean('consent_snapshot')->default(false);
            $table->unsignedInteger('current_step')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->unique(['marketing_campaign_id', 'recipient_type', 'recipient_id'], 'campaign_enrollments_unique_recipient');
            // The drip runner's due-scan predicate.
            $table->index(['status', 'next_run_at']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_enrollments');
    }
};
