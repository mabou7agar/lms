<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalized per-course rating summary, kept in its OWN table so the Catalog Course model is never
 * touched. course_id is the primary key AND a FK to courses. Every column is derived from source
 * reviews by ReviewAggregateService (idempotent recompute) — this row is a cache, not a ledger, so
 * it tracks only updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_review_aggregates', function (Blueprint $table) {
            $table->unsignedBigInteger('course_id')->primary();
            $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();

            $table->unsignedInteger('reviews_count')->default(0);
            $table->unsignedBigInteger('ratings_sum')->default(0);
            $table->decimal('avg_rating', 3, 2)->default(0);

            $table->unsignedInteger('dist_1')->default(0);
            $table->unsignedInteger('dist_2')->default(0);
            $table->unsignedInteger('dist_3')->default(0);
            $table->unsignedInteger('dist_4')->default(0);
            $table->unsignedInteger('dist_5')->default(0);

            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_review_aggregates');
    }
};
