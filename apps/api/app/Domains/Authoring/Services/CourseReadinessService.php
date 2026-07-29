<?php

namespace App\Domains\Authoring\Services;

use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Platform\Shared\Assessment\Contracts\LessonAssessmentPort;
use App\Platform\Shared\Enums\Visibility;
use App\Platform\Shared\Publishing\Data\CourseReadinessInput;
use App\Platform\Shared\Publishing\Data\ReadinessIssue;
use App\Platform\Shared\Publishing\Data\ReadinessReport;
use App\Platform\Shared\Publishing\Enums\ReadinessSeverity;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Evaluates whether a course is fit to publish, and explains itself.
 *
 * This is the only place course publish rules live. The publish guard reads its verdict from the
 * report produced here rather than running a parallel check, so the readiness panel an author sees
 * and the guard that refuses their publish can never disagree.
 *
 * Course facts arrive as a flat CourseReadinessInput rather than a Course model: Authoring may not
 * depend on Catalog, and Catalog owns the mapping. Curriculum is read from Authoring's own models.
 *
 * Every rule answers three questions for the author: what is wrong, why it matters, and what to do
 * about it — plus which entity to open. An issue the author cannot act on is a bug in the rule.
 *
 * On severity: a blocker must describe something that is genuinely broken for a learner AND that
 * could not already be true of published content, because adding a blocker retroactively prevents
 * existing courses from re-publishing. When in doubt, warn.
 */
class CourseReadinessService extends BaseService
{
    public function __construct(private readonly LessonAssessmentPort $assessments) {}

    public function evaluate(CourseReadinessInput $course): ReadinessReport
    {
        /** @var list<ReadinessIssue> $issues */
        $issues = [];
        /** @var list<string> $passed */
        $passed = [];

        $this->checkMetadata($course, $issues, $passed);

        $sectionIds = Section::where('course_id', $course->courseId)->pluck('id');

        if ($sectionIds->isEmpty()) {
            $issues[] = new ReadinessIssue(
                code: 'course.no_sections',
                severity: ReadinessSeverity::Blocker,
                title: 'The course has no sections.',
                explanation: 'A course needs at least one section before learners have anything to open.',
                recommendedAction: 'Add a section in the Course Builder.',
                entityType: 'course',
                entityPublicId: $course->coursePublicId,
            );

            // Every remaining rule reads lessons, which cannot exist without a section. Returning
            // here avoids reporting a cascade of consequences that all have the same single cause.
            return new ReadinessReport($issues, $passed, now()->toIso8601String());
        }

        $passed[] = 'course.no_sections';

        /** @var Collection<int, Lesson> $lessons */
        $lessons = Lesson::whereIn('section_id', $sectionIds)
            ->with('media:id,lesson_id')
            ->get();

        $this->checkPublishedLessons($lessons, $course, $issues, $passed);
        $this->checkLessonContent($lessons, $issues, $passed);
        $this->checkQuizAssessments($lessons, $issues, $passed);

        return new ReadinessReport($issues, $passed, now()->toIso8601String());
    }

    /**
     * @param  list<ReadinessIssue>  $issues
     * @param  list<string>  $passed
     */
    private function checkMetadata(CourseReadinessInput $course, array &$issues, array &$passed): void
    {
        // Warnings, not blockers: a thin listing page is a marketing problem, not a broken course.
        // The title is not checked because the column is non-nullable — a rule that can never fire
        // is noise in the panel.
        if (trim((string) $course->description) === '') {
            $issues[] = new ReadinessIssue(
                code: 'course.missing_description',
                severity: ReadinessSeverity::Warning,
                title: 'The course has no description.',
                explanation: 'The description is what prospective learners read on the catalog page.',
                recommendedAction: 'Add a description in course settings.',
                entityType: 'course',
                entityPublicId: $course->coursePublicId,
            );
        } else {
            $passed[] = 'course.missing_description';
        }

        if (trim((string) $course->thumbnailPath) === '') {
            $issues[] = new ReadinessIssue(
                code: 'course.missing_thumbnail',
                severity: ReadinessSeverity::Warning,
                title: 'The course has no thumbnail.',
                explanation: 'Courses without an image are noticeably weaker in catalog listings.',
                recommendedAction: 'Upload a thumbnail in course settings.',
                entityType: 'course',
                entityPublicId: $course->coursePublicId,
            );
        } else {
            $passed[] = 'course.missing_thumbnail';
        }

        if (! $course->hasInstructor) {
            $issues[] = new ReadinessIssue(
                code: 'course.no_instructor',
                severity: ReadinessSeverity::Warning,
                title: 'No instructor is assigned to this course.',
                explanation: 'The course page will show no one teaching it, and it will not appear on any trainer profile.',
                recommendedAction: 'Assign at least one instructor to the course.',
                entityType: 'course',
                entityPublicId: $course->coursePublicId,
            );
        } else {
            $passed[] = 'course.no_instructor';
        }

        $this->checkVisibility($course, $issues, $passed);
    }

    /**
     * Publishing a course does not put it in the catalog — the catalog listing also requires
     * `visibility = public` (Course::scopeVisible). An author who publishes a private course and
     * then cannot find it has hit exactly this, and today nothing tells them why.
     *
     * WARNING, not a blocker, on two grounds. Private and unlisted courses are a legitimate
     * shipping mode — internal, cohort-gated, or link-shared content is published on purpose — so
     * refusing the publish would break a real workflow. And promoting it to a blocker would
     * retroactively stop every already-published non-public course from re-publishing, which the
     * severity policy on ReadinessSeverity explicitly rules out.
     *
     * An unrecognised value is reported too rather than ignored: the column is a plain string with
     * a default, so a bad write is possible, and silently treating it as fine would hide it.
     *
     * @param  list<ReadinessIssue>  $issues
     * @param  list<string>  $passed
     */
    private function checkVisibility(CourseReadinessInput $course, array &$issues, array &$passed): void
    {
        $visibility = $course->visibility;

        if ($visibility !== null && Visibility::tryFrom($visibility)?->isPublic() === true) {
            $passed[] = 'course.not_publicly_visible';

            return;
        }

        $known = $visibility !== null && Visibility::tryFrom($visibility) !== null;

        $issues[] = new ReadinessIssue(
            code: $known ? 'course.not_publicly_visible' : 'course.invalid_visibility',
            severity: ReadinessSeverity::Warning,
            title: $known
                ? sprintf('The course visibility is "%s", so it will not appear in the catalog.', $visibility)
                : 'The course visibility is not set to a recognised value.',
            explanation: $known
                ? 'Publishing makes the course available, but only public courses are listed in the catalog. Learners will need a direct link or an enrollment you create for them.'
                : 'The catalog lists only courses marked public, and an unrecognised value is treated as not public.',
            recommendedAction: $known
                ? 'Set visibility to public in course settings if you intend learners to find it by browsing.'
                : sprintf('Set visibility in course settings to one of: %s.', implode(', ', Visibility::values())),
            entityType: 'course',
            entityPublicId: $course->coursePublicId,
        );
    }

    /**
     * @param  Collection<int, Lesson>  $lessons
     * @param  list<ReadinessIssue>  $issues
     * @param  list<string>  $passed
     */
    private function checkPublishedLessons(Collection $lessons, CourseReadinessInput $course, array &$issues, array &$passed): void
    {
        if (! config('authoring.publish.require_published_lesson', true)) {
            return;
        }

        if ($lessons->contains(fn (Lesson $l) => $l->publish_state->isPublished())) {
            $passed[] = 'course.no_published_lesson';

            return;
        }

        $issues[] = new ReadinessIssue(
            code: 'course.no_published_lesson',
            severity: ReadinessSeverity::Blocker,
            title: 'The course has no published lessons.',
            explanation: 'Draft lessons are invisible to learners, so an enrolled learner would see an empty course.',
            recommendedAction: 'Publish at least one lesson in the Course Builder.',
            entityType: 'course',
            entityPublicId: $course->coursePublicId,
        );
    }

    /**
     * A published lesson carrying neither content nor media is a dead end for a learner.
     *
     * WARNING, not blocker, and deliberately so: publishing has never required lesson content, so
     * making this fatal would retroactively strand every existing course that has a thin lesson —
     * breaking authors who did nothing wrong under the old rules. It is advice, loudly given.
     *
     * Draft lessons are skipped entirely; unfinished work parked in draft is the point of draft.
     *
     * @param  Collection<int, Lesson>  $lessons
     * @param  list<ReadinessIssue>  $issues
     * @param  list<string>  $passed
     */
    private function checkLessonContent(Collection $lessons, array &$issues, array &$passed): void
    {
        $empty = $lessons->filter(function (Lesson $lesson): bool {
            if (! $lesson->publish_state->isPublished()) {
                return false;
            }

            // Quiz lessons carry their substance in the linked assessment, not in `content`, and are
            // covered by their own rule below. Checking them here would report every quiz twice.
            if ($lesson->type->usesAssessment()) {
                return false;
            }

            $content = $lesson->content;

            return ! (is_array($content) && $content !== []) && $lesson->media === null;
        });

        if ($empty->isEmpty()) {
            $passed[] = 'lesson.empty_content';

            return;
        }

        foreach ($empty as $lesson) {
            $issues[] = new ReadinessIssue(
                code: 'lesson.empty_content',
                severity: ReadinessSeverity::Warning,
                title: sprintf('The lesson "%s" is published but empty.', $lesson->title),
                explanation: 'It has neither content nor media, so a learner who opens it sees nothing.',
                recommendedAction: 'Add content to the lesson, or return it to draft until it is ready.',
                entityType: 'lesson',
                entityPublicId: $lesson->public_id,
            );
        }
    }

    /**
     * A quiz lesson pointing at a missing, draft or archived assessment renders as unavailable for
     * the learner — the publish-gated reference resolves to null. That is a broken lesson, and it
     * blocks: quiz lessons are new, so no existing published course can already be in this state.
     *
     * Resolved through LessonAssessmentPort rather than by querying assessments: Authoring is not
     * permitted to import an Assessment class, and an ArchitectureTest enforces it.
     *
     * @param  Collection<int, Lesson>  $lessons
     * @param  list<ReadinessIssue>  $issues
     * @param  list<string>  $passed
     */
    private function checkQuizAssessments(Collection $lessons, array &$issues, array &$passed): void
    {
        $quizzes = $lessons->filter(
            fn (Lesson $l) => $l->type->usesAssessment() && $l->publish_state->isPublished(),
        );

        if ($quizzes->isEmpty()) {
            return;
        }

        $broken = false;

        foreach ($quizzes as $lesson) {
            $ref = $lesson->assessment_id === null ? null : $this->assessments->describe($lesson->assessment_id);

            if ($ref !== null && $ref->isPublished()) {
                continue;
            }

            $broken = true;

            $issues[] = new ReadinessIssue(
                code: 'lesson.quiz_without_published_assessment',
                severity: ReadinessSeverity::Blocker,
                title: sprintf('The quiz lesson "%s" has no published quiz.', $lesson->title),
                explanation: $ref === null
                    ? 'No quiz is attached, so the lesson has nothing for a learner to take.'
                    : 'The attached quiz is still a draft, so learners see the lesson as unavailable.',
                recommendedAction: $ref === null
                    ? 'Attach a quiz to this lesson, or change the lesson type.'
                    : 'Publish the attached quiz.',
                entityType: 'lesson',
                entityPublicId: $lesson->public_id,
            );
        }

        if (! $broken) {
            $passed[] = 'lesson.quiz_without_published_assessment';
        }
    }
}
