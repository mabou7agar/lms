<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-learner completion of individual content blocks within a lesson. Drives the "required blocks"
 * half of the lesson completion rule. `block_ref` is the block PUBLIC id (a string) — a
 * cross-context reference to an Authoring block, so it is stored as an indexed scalar with no FK.
 * enrollment_id FKs within Learning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_block_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('lesson_id');
            $table->string('block_ref', 64);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'block_ref']);
            $table->index(['user_id', 'lesson_id']);
            $table->index(['enrollment_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_block_progress');
    }
};
