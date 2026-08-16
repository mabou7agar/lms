<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Q&A needs to be measurable and to hold a private conversation.
 *
 * RESPONSE METRICS. `first_response_at` is stamped when an INSTRUCTOR first answers — not when any
 * learner does. The service-level promise is about the course team being responsive; a helpful peer
 * answering first is a good thing, but it is not the instructor having replied, and counting it as
 * one would let a course look attentive while its instructor never showed up. `first_response_minutes`
 * is stored alongside rather than derived on read so the SLA report is a plain aggregate over a
 * column instead of a per-row date subtraction across a join.
 *
 * VISIBILITY. A question is public to fellow learners by default, which is what makes a course Q&A
 * worth reading. `private` restricts it to the asker and the course team, for the questions people
 * will not ask in front of a class.
 *
 * `is_official` marks the course team's authoritative answer, which is a different claim from the
 * asker's `accepted`: one says "this is correct", the other says "this solved my problem".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_questions', function (Blueprint $table): void {
            $table->string('visibility', 16)->default('public')->after('body');
            $table->timestamp('first_response_at')->nullable()->after('answers_count');
            $table->unsignedInteger('first_response_minutes')->nullable()->after('first_response_at');
            $table->timestamp('closed_at')->nullable()->after('first_response_minutes');

            // The instructor inbox reads "unanswered questions on my courses, oldest first"; the SLA
            // report reads the same rows to find the overdue ones.
            $table->index(['course_id', 'first_response_at'], 'course_questions_course_first_response_index');
        });

        Schema::table('question_answers', function (Blueprint $table): void {
            $table->boolean('is_official')->default(false)->after('is_instructor');
        });
    }

    public function down(): void
    {
        Schema::table('course_questions', function (Blueprint $table): void {
            $table->dropIndex('course_questions_course_first_response_index');
            $table->dropColumn(['visibility', 'first_response_at', 'first_response_minutes', 'closed_at']);
        });

        Schema::table('question_answers', function (Blueprint $table): void {
            $table->dropColumn('is_official');
        });
    }
};
