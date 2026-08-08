<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Helpful" votes on reviews. The unique (review_id, user_id) index makes a learner's vote at most
 * one; the review's helpful_count is always recomputed from these rows, so voting is idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_review_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('course_reviews')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['review_id', 'user_id'], 'course_review_votes_review_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_review_votes');
    }
};
