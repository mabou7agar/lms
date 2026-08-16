<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Downloadable material attached to a course, or to one lesson inside it — the workbook, the slide
 * deck, the dataset an exercise works on.
 *
 * A row is a PUBLICATION DECISION, not a file: the bytes live in the media library and are reached
 * only through a short-lived signed URL. That separation is what lets the same asset back several
 * courses, keeps raw storage keys out of every payload, and means revoking a course's access does
 * not require touching storage.
 *
 * `lesson_id` null means the resource belongs to the whole course; set, it belongs to that lesson and
 * shows up in the player beside it. Both live in one table because they are the same thing at two
 * scopes, and splitting them would duplicate every rule about visibility and ordering.
 *
 * `visibility` is deliberately NOT the media asset's own visibility. The asset may be private in the
 * library while a course chooses to show one file as a free sample; this column is the course's
 * decision, and the entitlement check still runs for everything except an explicit preview.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_resources', function (Blueprint $table): void {
            $table->id();
            $table->publicId();

            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // Snapshotted from the asset at attach time so a resource list can say "PDF, 2.4 MB"
            // without a media lookup per row — a learner on mobile data deserves to know what a tap
            // is about to cost them, and that is not worth N queries to answer.
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            // 'enrolled' (default) or 'preview'. A preview file is the course's advertisement; an
            // enrolled file is part of what was paid for.
            $table->string('visibility', 16)->default('enrolled');
            // An attached file that is not downloadable is still listed — "here is what you get" —
            // which is why this is separate from visibility.
            $table->boolean('downloadable')->default(true);

            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The two reads this table serves: a course's resource list, and one lesson's panel.
            $table->index(['course_id', 'position'], 'course_resources_course_position_index');
            $table->index(['lesson_id', 'position'], 'course_resources_lesson_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_resources');
    }
};
