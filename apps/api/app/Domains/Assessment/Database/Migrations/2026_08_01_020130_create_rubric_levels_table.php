<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One selectable performance level within a criterion (e.g. "Exemplary = 4 pts"). position is
 * unique within its criterion. A CHECK keeps points non-negative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubric_levels', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('criterion_id')->constrained('rubric_criteria')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('points', 8, 2)->default(0);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->index('criterion_id');
            $table->unique(['criterion_id', 'position'], 'rubric_levels_position_unique');
        });

        DB::statement('ALTER TABLE rubric_levels ADD CONSTRAINT rubric_levels_points_nonneg CHECK (points >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('rubric_levels');
    }
};
