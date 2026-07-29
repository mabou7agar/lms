<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row/criterion of a rubric. max_points is the highest level points beneath it (deterministic).
 * position is unique within a rubric so ordering is total and reproducible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubric_criteria', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('rubric_id')->constrained('assignment_rubrics')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position');
            $table->decimal('max_points', 8, 2)->default(0);
            $table->timestamps();

            $table->index('rubric_id');
            $table->unique(['rubric_id', 'position'], 'rubric_criteria_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rubric_criteria');
    }
};
