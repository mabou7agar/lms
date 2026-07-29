<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-learner video playback progress. Separate from lesson_progress so the high-frequency,
 * throttled heartbeat writes (resume position + watched duration) never contend with the coarse
 * lesson status row, and so completion stays server-authoritative (a `completed` flag the server
 * sets when watched duration crosses the threshold — never a client-sent boolean).
 *
 * lesson_id / user_id are cross-context scalar references (indexed, no FK): the lessons/users
 * tables belong to Authoring/Identity. enrollment_id FKs within Learning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_video_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('lesson_id');
            $table->unsignedInteger('position_seconds')->default(0); // resume point (latest)
            $table->unsignedInteger('watched_seconds')->default(0);  // furthest watched (for threshold)
            $table->unsignedInteger('duration_seconds')->nullable(); // server-known duration
            $table->boolean('completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_beat_at')->nullable(); // throttle anchor
            $table->timestamps();

            $table->unique(['enrollment_id', 'lesson_id']);
            $table->index(['user_id', 'lesson_id']);
            $table->index('lesson_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_video_progress');
    }
};
