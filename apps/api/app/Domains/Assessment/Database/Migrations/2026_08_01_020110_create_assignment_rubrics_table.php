<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rubric belongs to exactly one assignment. total_points is a DETERMINISTIC roll-up (sum of each
 * criterion's highest level points) recomputed on every rubric build, so it never drifts from its
 * criteria/levels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_rubrics', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('assignment_id')->constrained('assignments')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->decimal('total_points', 8, 2)->default(0);
            $table->timestamps();

            $table->index('assignment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_rubrics');
    }
};
