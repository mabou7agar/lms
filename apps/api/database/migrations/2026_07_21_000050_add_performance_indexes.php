<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 (H1) — the systemic missing indexes.
 *
 * PostgreSQL does NOT auto-index foreign keys, and this schema declares them with
 * foreignId()->constrained() almost everywhere without a follow-up index(), so the hottest
 * filter/join columns sequential-scan. Every index below is traceable to a named hot query in
 * STEP_6_TO_9_ENGINEERING_AUDIT.md; none is speculative, and none duplicates an existing index or a
 * covering composite prefix. Two existing narrow indexes become redundant prefixes of new composites
 * and are dropped to avoid duplication and needless write cost.
 *
 * Column order follows: equality predicate → scope (course/user) → range/date → ordering.
 *
 * LOCK RISK: this is a plain transactional migration. On a fresh database (CI, new environments)
 * every table is empty and index creation is instant. On a POPULATED production table a
 * non-concurrent CREATE INDEX takes a SHARE lock that blocks writes (reads continue) for the build.
 * The operator note in the Sprint 6 report gives the CREATE INDEX CONCURRENTLY commands to apply by
 * hand on large production tables instead of running this migration there. This migration is not
 * claimed to be zero-downtime on populated tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        // enrollments — the hottest table. course_id is never a leading column today (only the
        // (user_id,course_id) unique and (user_id,status) index exist).
        Schema::table('enrollments', function (Blueprint $table) {
            // Course-scoped status counts (statsForCourses active/completed, StudentController).
            $table->index(['course_id', 'status'], 'enrollments_course_id_status_index');
            // The M5 windowed aggregate: WHERE course_id IN (...) AND enrolled_at >= s AND < e,
            // GROUP BY course_id. Equality (course_id) then range (enrolled_at).
            $table->index(['course_id', 'enrolled_at'], 'enrollments_course_id_enrolled_at_index');
        });

        // orders — revenue/commerce reports filter paid_at/created_at windows and join coupon_id.
        Schema::table('orders', function (Blueprint $table) {
            $table->index('paid_at', 'orders_paid_at_index');
            $table->index('created_at', 'orders_created_at_index');
            $table->index('coupon_id', 'orders_coupon_id_index');
        });

        // order_items — order_id and product_id are constrained() FKs with no index; both are join
        // keys in the revenue-by-course/product reports.
        Schema::table('order_items', function (Blueprint $table) {
            $table->index('order_id', 'order_items_order_id_index');
            $table->index('product_id', 'order_items_product_id_index');
        });

        // product_courses — reports group/join on course_id, which is the TRAILING column of the
        // (product_id, course_id) primary key and so cannot be used as a leading key.
        Schema::table('product_courses', function (Blueprint $table) {
            $table->index('course_id', 'product_courses_course_id_index');
        });

        // course_trainer — instructorPerformance groups by user_id, the TRAILING column of the
        // (course_id, user_id) primary key.
        Schema::table('course_trainer', function (Blueprint $table) {
            $table->index('user_id', 'course_trainer_user_id_index');
        });

        // certificates — reports filter status + issued_at. The existing standalone status index is
        // a redundant prefix of the new composite, so drop it.
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropIndex('certificates_status_index');
            $table->index(['status', 'issued_at'], 'certificates_status_issued_at_index');
        });

        // lesson_progress — completion reports filter status=completed AND completed_at range. Not
        // covered by the existing (lesson_id, status) index.
        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->index(['status', 'completed_at'], 'lesson_progress_status_completed_at_index');
        });

        // courses — the public catalog is WHERE status=published AND visibility=public
        // ORDER BY is_featured DESC, published_at DESC. A single composite covers the filter AND the
        // ordering. The existing (status, visibility) index is a redundant prefix of it — drop it.
        // The standalone is_featured index is kept: is_featured is the 3rd column here and so this
        // composite cannot serve an is_featured-only lookup.
        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex('courses_status_visibility_index');
            $table->index(['status', 'visibility', 'is_featured', 'published_at'], 'courses_catalog_listing_index');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropIndex('enrollments_course_id_status_index');
            $table->dropIndex('enrollments_course_id_enrolled_at_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_paid_at_index');
            $table->dropIndex('orders_created_at_index');
            $table->dropIndex('orders_coupon_id_index');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_order_id_index');
            $table->dropIndex('order_items_product_id_index');
        });

        Schema::table('product_courses', function (Blueprint $table) {
            $table->dropIndex('product_courses_course_id_index');
        });

        Schema::table('course_trainer', function (Blueprint $table) {
            $table->dropIndex('course_trainer_user_id_index');
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->dropIndex('certificates_status_issued_at_index');
            $table->index('status', 'certificates_status_index'); // restore the original
        });

        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->dropIndex('lesson_progress_status_completed_at_index');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex('courses_catalog_listing_index');
            $table->index(['status', 'visibility'], 'courses_status_visibility_index'); // restore the original
        });
    }
};
