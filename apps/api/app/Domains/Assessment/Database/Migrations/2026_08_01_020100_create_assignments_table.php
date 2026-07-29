<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An instructor-reviewed assignment. Independent of the auto-graded Assessment (quiz) entity but
 * living in the same domain so a single gradebook can span both.
 *
 * course_id is the AUTHORIZATION anchor and lesson_id the (optional) curriculum placement. Both are
 * scalar, indexed, NOT foreign keys: this context may not couple its schema to Catalog/Authoring
 * tables across the bounded-context boundary (W04 rule). rubric_id points at the assignment's
 * active rubric and is a plain nullable id (no FK) to avoid a circular constraint with
 * assignment_rubrics.assignment_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->publicId();

            // Cross-context references: scalar + index, never a FK across a domain boundary.
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('lesson_id')->nullable();

            $table->string('title');
            $table->jsonb('instructions')->nullable();      // sanitized rich-text blocks

            $table->string('submission_type', 24)->default('file');   // SubmissionType

            // File-submission constraints. Ignored for text/external_url types.
            $table->jsonb('allowed_file_types')->nullable();          // e.g. ["pdf","docx"]
            $table->unsignedBigInteger('max_file_size')->nullable();  // bytes; null = platform default
            $table->unsignedSmallInteger('max_files')->default(1);

            // null = unlimited resubmissions.
            $table->unsignedSmallInteger('attempt_limit')->nullable();

            $table->timestamp('due_at')->nullable();
            $table->string('late_policy', 16)->default('allowed');    // LatePolicy
            $table->unsignedTinyInteger('late_penalty_percent')->nullable(); // used by 'penalised'

            $table->decimal('max_grade', 8, 2)->default(100);
            $table->decimal('passing_grade', 8, 2)->nullable();       // null = accept/return only

            // Active rubric for this assignment. No FK (circular with assignment_rubrics).
            $table->unsignedBigInteger('rubric_id')->nullable();

            $table->string('publish_state', 16)->default('draft');    // AssignmentState
            $table->boolean('required_for_completion')->default(false);

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('course_id');
            $table->index('lesson_id');
            $table->index(['course_id', 'publish_state']);
            $table->index(['lesson_id', 'required_for_completion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
