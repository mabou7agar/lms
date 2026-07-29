<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One learner attempt at one assignment.
 *
 * rubric_snapshot is an IMMUTABLE copy of the rubric (criteria + levels + totals) captured at
 * submit time, so later rubric edits never rewrite the standard historical work was graded against.
 *
 * Invariants enforced at the database, not just in code:
 *  - unique (assignment_id, user_id, attempt_no): attempt numbers are dense and non-colliding.
 *  - partial unique on (assignment_id, user_id) WHERE status='draft': at most one open draft per
 *    learner per assignment, so concurrent "save draft" calls cannot fork two working copies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('assignment_id')->constrained('assignments')->cascadeOnDelete();

            // Learner identity is a scalar user id (no cross-context FK).
            $table->unsignedBigInteger('user_id');

            $table->unsignedSmallInteger('attempt_no')->default(1);
            $table->string('status', 24)->default('draft');   // SubmissionStatus

            $table->timestamp('submitted_at')->nullable();
            $table->boolean('is_late')->default(false);

            // Frozen rubric copy; null when the assignment has no rubric.
            $table->jsonb('rubric_snapshot')->nullable();

            $table->text('text_response')->nullable();
            $table->string('external_url', 2048)->nullable();

            $table->timestamps();

            $table->unique(['assignment_id', 'user_id', 'attempt_no'], 'assignment_submission_attempt_unique');
            $table->index(['assignment_id', 'user_id']);
            $table->index('status');
            $table->index('user_id');
        });

        // At most one non-terminal DRAFT per learner per assignment.
        DB::statement(
            "CREATE UNIQUE INDEX assignment_submission_one_draft ON assignment_submissions (assignment_id, user_id) WHERE status = 'draft'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submissions');
    }
};
