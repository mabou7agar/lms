<?php

use App\Domains\Assessment\Actions\Assessment\AttachAssessmentToLessonAction;
use App\Domains\Assessment\Models\Assessment;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** A lesson inside a fresh course, returned with its owning course id. */
function lessonInCourse(): array
{
    $course = Course::factory()->create(['status' => CourseStatus::Draft]);
    $section = Section::factory()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->create(['section_id' => $section->id]);

    return [$course, $lesson];
}

it('attaches an assessment to a lesson in the same course', function () {
    [$course, $lesson] = lessonInCourse();
    $assessment = Assessment::factory()->create(['course_id' => $course->id]);

    app(AttachAssessmentToLessonAction::class)->attach($assessment, (int) $lesson->id);

    expect((int) $lesson->fresh()->assessment_id)->toBe((int) $assessment->id);
});

it('detaches an assessment only from a lesson that references it', function () {
    [$course, $lesson] = lessonInCourse();
    $assessment = Assessment::factory()->create(['course_id' => $course->id]);
    $action = app(AttachAssessmentToLessonAction::class);

    $action->attach($assessment, (int) $lesson->id);
    $action->detach($assessment, (int) $lesson->id);

    expect($lesson->fresh()->assessment_id)->toBeNull();

    // A different assessment's detach must NOT clear this lesson's reference.
    $other = Assessment::factory()->create(['course_id' => $course->id]);
    $action->attach($assessment, (int) $lesson->id);
    $action->detach($other, (int) $lesson->id);

    expect((int) $lesson->fresh()->assessment_id)->toBe((int) $assessment->id);
});

it('refuses to attach an assessment to a lesson in a different course', function () {
    [$courseA] = lessonInCourse();
    [, $lessonB] = lessonInCourse();
    $assessment = Assessment::factory()->create(['course_id' => $courseA->id]);

    app(AttachAssessmentToLessonAction::class)->attach($assessment, (int) $lessonB->id);
})->throws(ValidationException::class);

it('refuses to attach an archived assessment', function () {
    [$course, $lesson] = lessonInCourse();
    $assessment = Assessment::factory()->archived()->create(['course_id' => $course->id]);

    app(AttachAssessmentToLessonAction::class)->attach($assessment, (int) $lesson->id);
})->throws(ValidationException::class);

it('refuses to attach a platform-level assessment with no course', function () {
    [, $lesson] = lessonInCourse();
    $assessment = Assessment::factory()->create(['course_id' => null]);

    app(AttachAssessmentToLessonAction::class)->attach($assessment, (int) $lesson->id);
})->throws(ValidationException::class);
