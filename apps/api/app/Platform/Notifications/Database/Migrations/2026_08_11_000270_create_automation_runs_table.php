<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency ledger for the automation engine. A rule fires AT MOST ONCE for a given
 * (rule, subject, event) triple: the unique key makes a redispatched or replayed domain event a
 * no-op instead of re-running the rule's actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->cascadeOnDelete();
            // Stable identity of the fired occurrence: the subject aggregate + the event dedupe key.
            $table->string('subject_key');
            $table->string('event_key');
            $table->timestamp('fired_at');
            $table->timestamps();

            $table->unique(['automation_rule_id', 'subject_key', 'event_key'], 'automation_runs_unique_fire');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
