<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a quiz be flagged as required-for-completion, mirroring assignments.required_for_completion.
 * The Learning completion engine reads this only through AssessmentResultPort::requiredAssessmentIdsForCourse
 * (course-scoped + required), so no other context ever queries this column directly.
 *
 * Default false keeps every existing assessment inert: nothing becomes a completion gate until an
 * admin opts a specific assessment in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->boolean('required_for_completion')->default(false)->after('passing_score');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn('required_for_completion');
        });
    }
};
