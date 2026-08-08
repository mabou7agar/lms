<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U4 - Multi-instructor course assignment. The course_trainer pivot already supported MANY trainers
 * per course with an explicit `position` order and a composite (course_id, user_id) PRIMARY KEY —
 * which is exactly the required unique (course_id, instructor_id) integrity constraint, so it is
 * reused as-is. This migration adds the two missing facets, forward-only + additive:
 *
 *   • role       — the trainer's role/title on this course (nullable free text, e.g. "Lead", "TA").
 *   • is_primary — marks the single primary instructor. "At most one primary per course" is an
 *                  application invariant (portable across sqlite/mysql — no partial unique index):
 *                  enforced centrally by CourseTrainer's saved hook AND CourseInstructorService.
 *
 * TENANCY NOTE (T1, later phase): course_trainer carries no tenant column. Cross-tenant instructor
 * assignment is prevented today only by the course being tenant-scoped upstream; when T1 lands the
 * assignment authorization in CourseInstructorService must additionally assert the instructor belongs
 * to the same organization as the course.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_trainer', function (Blueprint $table): void {
            $table->string('role')->nullable()->after('user_id');
            $table->boolean('is_primary')->default(false)->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('course_trainer', function (Blueprint $table): void {
            $table->dropColumn(['role', 'is_primary']);
        });
    }
};
