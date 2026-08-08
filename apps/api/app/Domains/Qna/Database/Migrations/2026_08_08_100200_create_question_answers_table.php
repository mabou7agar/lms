<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An answer to a course question. Tenancy is inherited transitively through the parent question
 * (every read reaches an answer through its tenant-scoped question), so this table carries no
 * tenant column. `is_instructor` is DERIVED once at create time (course ownership via
 * CourseAccessPort) and frozen — it is a badge of who answered, not a live role check.
 *
 * Also attaches the deferred FK `course_questions.accepted_answer_id -> question_answers.id`, which
 * could not be declared while creating course_questions (this table did not exist yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_answers', function (Blueprint $table): void {
            $table->id();
            $table->publicId();

            $table->foreignId('question_id')->constrained('course_questions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->text('body'); // sanitized on write

            $table->boolean('is_instructor')->default(false);
            $table->boolean('accepted')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index('question_id');
        });

        Schema::table('course_questions', function (Blueprint $table): void {
            $table->foreign('accepted_answer_id')
                ->references('id')->on('question_answers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('course_questions', function (Blueprint $table): void {
            $table->dropForeign(['accepted_answer_id']);
        });

        Schema::dropIfExists('question_answers');
    }
};
