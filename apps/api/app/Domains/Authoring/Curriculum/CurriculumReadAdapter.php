<?php

namespace App\Domains\Authoring\Curriculum;

use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Curriculum\Data\CourseRef;
use App\Platform\Shared\Curriculum\Data\LessonRef;
use App\Platform\Shared\Curriculum\Data\SectionRef;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * TEMPORARY Phase-1 adapter for CurriculumReadPort. Centralizes the model→DTO mapping that
 * Learning resources previously did inline, and the enrollability check EnrollInCourseAction
 * previously did with CourseStatus. It reads Authoring's own Lesson/Section and (as existing,
 * baselined Authoring→Catalog debt) Catalog's Course. The audit's final design splits this into a
 * Catalog course provider + an Authoring section/lesson provider; this single adapter is the
 * intentional temporary bridge for Phase 1. Mappers read ALREADY-LOADED models only — no queries.
 */
class CurriculumReadAdapter implements CurriculumReadPort
{
    public function isCourseEnrollable(int $courseId): bool
    {
        $course = Course::query()->find($courseId);

        return $course !== null && $course->status === CourseStatus::Published;
    }

    public function courseRef(Model $course): CourseRef
    {
        assert($course instanceof Course);

        return new CourseRef(
            id: (int) $course->id,
            publicId: (string) $course->public_id,
            title: (string) $course->title,
            slug: (string) $course->slug,
        );
    }

    public function sectionRef(Model $section): SectionRef
    {
        assert($section instanceof Section);

        return new SectionRef(
            id: (int) $section->id,
            publicId: (string) $section->public_id,
            title: (string) $section->title,
        );
    }

    public function lessonRef(Model $lesson): LessonRef
    {
        assert($lesson instanceof Lesson);

        // Preserve the exact prior semantics: null when the media relation was not eager-loaded.
        $hasMedia = $lesson->relationLoaded('media') ? $lesson->media !== null : null;

        return new LessonRef(
            id: (int) $lesson->id,
            publicId: (string) $lesson->public_id,
            title: (string) $lesson->title,
            type: $lesson->type->value,
            isPreview: (bool) $lesson->is_preview,
            hasMedia: $hasMedia,
        );
    }

    /** @return list<int> */
    public function publishedLessonIdsForCourse(int $courseId): array
    {
        $sectionIds = Section::where('course_id', $courseId)->published()->pluck('id');

        return Lesson::whereIn('section_id', $sectionIds)->published()->pluck('id')->all();
    }

    /** @return list<int> */
    public function publishedLessonIdsForSection(int $sectionId): array
    {
        return Lesson::where('section_id', $sectionId)->published()->pluck('id')->all();
    }

    public function courseIdForLesson(int $lessonId): ?int
    {
        $sectionId = Lesson::query()->whereKey($lessonId)->value('section_id');

        if ($sectionId === null) {
            return null;
        }

        $courseId = Section::query()->whereKey($sectionId)->value('course_id');

        return $courseId !== null ? (int) $courseId : null;
    }

    /**
     * @param  list<int>  $lessonIds
     * @return array<int, list<int>>
     */
    public function prerequisitesForLessonIds(array $lessonIds): array
    {
        /** @var array<int, list<int>> $result */
        $result = array_fill_keys($lessonIds, []);

        if ($lessonIds === []) {
            return $result;
        }

        // One pass over the pivot instead of $lesson->prerequisites() per lesson. Mirrors the
        // relation exactly: prerequisites(self, 'lesson_prerequisites', 'lesson_id',
        // 'prerequisite_lesson_id') — this lesson's prerequisites are the prerequisite_lesson_id
        // values keyed by lesson_id. Prerequisite order within a lesson is irrelevant to callers
        // (membership only), matching the unordered pivot pluck it replaces.
        $rows = DB::table('lesson_prerequisites')
            ->whereIn('lesson_id', $lessonIds)
            ->get(['lesson_id', 'prerequisite_lesson_id']);

        foreach ($rows as $row) {
            $result[(int) $row->lesson_id][] = (int) $row->prerequisite_lesson_id;
        }

        return $result;
    }

    // --- Phase 3A id/ref read methods (expand). Additive; not yet wired to any caller. ---

    public function findCourseByPublicId(string $publicId): ?CourseRef
    {
        $course = Course::query()->where('public_id', $publicId)->first();

        return $course !== null ? $this->buildCourseRef($course) : null;
    }

    public function courseRefById(int $courseId): ?CourseRef
    {
        $course = Course::query()->find($courseId);

        return $course !== null ? $this->buildCourseRef($course) : null;
    }

    public function lessonRefById(int $lessonId): ?LessonRef
    {
        $lesson = Lesson::query()->with('media')->find($lessonId);

        if ($lesson === null) {
            return null;
        }

        return $this->buildLessonRef($lesson, $this->courseIdForLesson($lessonId) ?? 0, true);
    }

    public function findLessonByPublicId(string $publicId): ?LessonRef
    {
        $lesson = Lesson::query()->with('media')->where('public_id', $publicId)->first();

        if ($lesson === null) {
            return null;
        }

        return $this->buildLessonRef($lesson, $this->courseIdForLesson((int) $lesson->id) ?? 0, true);
    }

    /**
     * @return array{course: ?CourseRef, sections: list<array{section: SectionRef, lessons: list<LessonRef>}>}
     */
    public function curriculumTree(int $courseId, bool $publishedOnly): array
    {
        $course = Course::query()->find($courseId);

        if ($course === null) {
            return ['course' => null, 'sections' => []];
        }

        $query = Section::query()
            ->where('course_id', $courseId)
            ->with(['lessons' => function ($q) use ($publishedOnly): void {
                $q->with('media')->orderBy('position');
                if ($publishedOnly) {
                    $q->published();
                }
            }])
            ->orderBy('position');

        if ($publishedOnly) {
            $query->published();
        }

        $sections = [];
        foreach ($query->get() as $section) {
            $sections[] = [
                'section' => $this->sectionRef($section),
                'lessons' => $section->lessons
                    ->map(fn (Lesson $lesson): LessonRef => $this->buildLessonRef($lesson, $courseId, false))
                    ->all(),
            ];
        }

        return ['course' => $this->buildCourseRef($course), 'sections' => $sections];
    }

    /** @return list<LessonRef> */
    public function orderedPublishedLessonRefs(int $courseId): array
    {
        $sectionIds = Section::where('course_id', $courseId)->published()->orderBy('position')->pluck('id');

        return Lesson::whereIn('section_id', $sectionIds)
            ->published()
            ->orderBy('section_id')
            ->orderBy('position')
            ->get()
            ->map(fn (Lesson $lesson): LessonRef => $this->buildLessonRef($lesson, $courseId, false))
            ->all();
    }

    private function buildCourseRef(Course $course): CourseRef
    {
        return new CourseRef(
            id: (int) $course->id,
            publicId: (string) $course->public_id,
            title: (string) $course->title,
            slug: (string) $course->slug,
            thumbnailPath: $course->thumbnail_path !== null ? (string) $course->thumbnail_path : null,
        );
    }

    private function buildLessonRef(Lesson $lesson, int $courseId, bool $withDetails): LessonRef
    {
        $hasMedia = $lesson->relationLoaded('media') ? $lesson->media !== null : null;

        $prerequisiteLessonIds = $withDetails
            ? array_map(intval(...), $lesson->prerequisites()->pluck('lessons.id')->all())
            : [];

        return new LessonRef(
            id: (int) $lesson->id,
            publicId: (string) $lesson->public_id,
            title: (string) $lesson->title,
            type: $lesson->type->value,
            isPreview: (bool) $lesson->is_preview,
            hasMedia: $hasMedia,
            sectionId: (int) $lesson->section_id,
            courseId: $courseId,
            position: (int) $lesson->position,
            prerequisiteLessonIds: $prerequisiteLessonIds,
            content: $withDetails ? $lesson->content : null,
            // Only quiz lessons carry a reference. Every other type has the column null anyway,
            // but gating on the type keeps the intent explicit: a stray assessment_id on, say, an
            // article must never reach the learner payload.
            assessmentId: $lesson->type->usesAssessment() ? $lesson->assessment_id : null,
        );
    }
}
