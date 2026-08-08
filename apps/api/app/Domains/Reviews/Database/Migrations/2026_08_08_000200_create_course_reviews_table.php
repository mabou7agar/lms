<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One learner review per course. course_id/user_id are genuine FKs; organization_id is a
 * denormalized tenant stamp (nullable = global course) written server-side only. rating is bounded
 * 1..5 at the database via a CHECK constraint. At most one ACTIVE (non-soft-deleted) review per
 * (course, user) — a partial unique index — so a learner may delete and later re-review, while a
 * duplicate live review is impossible even under a race.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_reviews', function (Blueprint $table) {
            $table->id();
            $table->publicId();

            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();

            // Transitive tenancy stamp — never mass-assignable; visibility is derived by joining to
            // courses (see Reviews\Tenancy\CourseTenantScope), this column is for reporting only.
            $table->unsignedBigInteger('organization_id')->nullable();

            $table->unsignedTinyInteger('rating');
            $table->text('body')->nullable();
            $table->string('status', 16)->default('published'); // ReviewStatus
            $table->boolean('verified')->default(false);

            $table->text('instructor_response')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();

            $table->unsignedInteger('helpful_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index('course_id');
            $table->index('organization_id');
            $table->index('status');
        });

        // Rating domain 1..5 enforced at the database, not just in validation.
        DB::statement('ALTER TABLE course_reviews ADD CONSTRAINT course_reviews_rating_check CHECK (rating BETWEEN 1 AND 5)');

        // At most one live review per learner per course (soft-deleted rows free the slot).
        DB::statement('CREATE UNIQUE INDEX course_reviews_course_user_active_unique ON course_reviews (course_id, user_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('course_reviews');
    }
};
