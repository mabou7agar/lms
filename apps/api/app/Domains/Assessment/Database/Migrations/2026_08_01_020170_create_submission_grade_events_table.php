<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only history of every grading action on a submission (graded, regraded, changes_requested,
 * released, unreleased). Never updated or deleted; the audit trail of who scored what, when, at
 * which version. Kept separate from submission_grades so the current-grade row stays a single
 * mutable record while history grows unbounded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_grade_events', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('submission_id')->constrained('assignment_submissions')->cascadeOnDelete();
            $table->unsignedBigInteger('grader_id');
            $table->string('event', 32);      // graded|regraded|changes_requested|released|unreleased
            $table->decimal('score', 8, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->unsignedInteger('version');
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('submission_id');
            $table->index(['submission_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_grade_events');
    }
};
