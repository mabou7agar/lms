<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only record of things that happened, which is what makes a funnel answerable.
 *
 * Every report the platform has today is computed from state — orders, enrollments, certificates —
 * and state cannot answer "how many people looked at this course and did not buy it", because
 * looking leaves no row behind. This table is that missing half.
 *
 * APPEND-ONLY: rows are written and read, never updated. An event is a claim about a moment, and a
 * moment does not change its mind. `dedup_key` is uniquely indexed so a retried webhook or a
 * double-submitted form records the same fact once rather than inflating a count that somebody will
 * later plan a budget on.
 *
 * The dimension columns are denormalised on purpose. A funnel query groups by course, product,
 * organization and buyer type across millions of rows; resolving those through joins to tables that
 * may since have been edited would be both slower and wrong — an event says what was true then.
 * They carry NO foreign keys for the same reason: deleting a course must not delete the history of
 * people having viewed it.
 *
 * `metadata` is for the small, non-identifying extras a specific event needs. Nothing here may carry
 * PII: the actor is a user id, and everything else is an id, a count or a label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64);

            // Who and where. All nullable: a course view by a signed-out visitor has no actor, and
            // a refund has no course.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('instructor_id')->nullable();

            $table->string('product_type', 16)->nullable();   // course | bundle
            $table->string('buyer_type', 16)->nullable();     // individual | company

            // Attribution, when the client supplied it. Free-text by nature, so bounded in length
            // and never trusted as anything but a label.
            $table->string('utm_source', 64)->nullable();
            $table->string('utm_medium', 64)->nullable();
            $table->string('utm_campaign', 96)->nullable();

            // An opaque per-visit id used to stitch a funnel together. Not an identifier of a
            // person: it is generated client-side, is not stored against a user, and is meaningless
            // once the visit ends.
            $table->string('session_id', 64)->nullable();

            $table->unsignedInteger('value_minor')->nullable(); // money, where the event has an amount
            $table->json('metadata')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamps();

            // Written once per real occurrence; a retry resolves to the same row.
            $table->string('dedup_key', 191)->nullable()->unique();

            // The three shapes every report reads: a window of one event type, one course's history,
            // and one funnel's session.
            $table->index(['name', 'occurred_at'], 'analytics_events_name_time_index');
            $table->index(['course_id', 'name'], 'analytics_events_course_name_index');
            $table->index(['product_id', 'name'], 'analytics_events_product_name_index');
            $table->index(['organization_id', 'occurred_at'], 'analytics_events_org_time_index');
            $table->index('session_id', 'analytics_events_session_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
