<?php

namespace App\Domains\Catalog\Analytics;

use App\Domains\Catalog\Contracts\CoursePublishGuard;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Models\CourseTrainer;
use App\Platform\Shared\Learning\Contracts\EnrollmentStatsPort;
use App\Platform\Shared\Publishing\Data\CourseReadinessInput;
use Illuminate\Support\Collection;

/**
 * Instructor-scoped activity and alerts, built ONLY from persisted state.
 *
 * WHAT IS DELIBERATELY ABSENT, and why — each of these was requested and each would be invented:
 *
 *   failed publish attempts   Not persisted. A refused publish throws and returns 422; nothing
 *                             records it. Reporting "3 failed publishes" would require inventing
 *                             history that does not exist.
 *   learner risk              No risk model exists. See the dashboard's at_risk_learners, which is
 *                             reported unavailable for the same reason.
 *   revenue events            No instructor revenue backend.
 *   recent quiz submissions   Attempt rows ARE persisted, but surfacing them per learner from
 *                             Catalog needs a per-attempt read the Assessment port does not expose
 *                             — it answers aggregates only, by design. Adding a submissions feed
 *                             means widening that port, which is a deliberate decision rather than
 *                             something to slip in here.
 *
 * What IS reported comes from columns that genuinely hold the fact: course timestamps for edits and
 * publishes, and the readiness engine for blockers. Nothing is derived from a proxy.
 */
class InstructorActivityService
{
    public const DEFAULT_LIMIT = 10;

    /** A draft untouched for this long is surfaced as stale. */
    public const STALE_DRAFT_DAYS = 30;

    /**
     * Hard ceiling on how many courses the alerts panel evaluates readiness for in one request.
     *
     * Readiness is a multi-query evaluation per course, so an unbounded sweep of a large catalogue
     * is a request that gets slower the more successful the instructor is. The response says when
     * it has been truncated rather than presenting a partial list as complete.
     */
    public const MAX_EVALUATED_COURSES = 50;

    /**
     * Readiness comes through Catalog's own CoursePublishGuard contract, not Authoring's evaluator:
     * Catalog may not depend on Authoring, and this panel must give the same verdict as the guard
     * the publish endpoint enforces.
     */
    public function __construct(
        private readonly CoursePublishGuard $readiness,
        private readonly EnrollmentStatsPort $enrollments,
    ) {}

    /**
     * Authoring activity: what this instructor has recently worked on and shipped.
     *
     * `updated_at` is a real column maintained by Eloquent, so "recently edited" is a fact rather
     * than an inference. It does NOT say what changed — see ChangeSummary for why that is a
     * separate, currently unavailable question.
     *
     * @param  list<int>  $courseIds  already authorization-scoped
     * @return array<string, mixed>
     */
    public function authoringActivity(array $courseIds, int $limit = self::DEFAULT_LIMIT): array
    {
        if ($courseIds === []) {
            return ['recently_edited' => [], 'recently_published' => []];
        }

        $recentlyEdited = Course::query()
            ->whereIn('id', $courseIds)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['public_id', 'title', 'status', 'updated_at'])
            ->map(fn (Course $c): array => [
                'id' => (string) $c->getAttribute('public_id'),
                'title' => (string) $c->getAttribute('title'),
                'status' => $c->getAttribute('status')->value,
                'occurred_at' => $c->getAttribute('updated_at')?->toIso8601String(),
            ])->all();

        // published_at is stamped on first publish and deliberately sticky thereafter, so this is
        // "courses that have ever been published, most recent first" — not a re-publish log.
        $recentlyPublished = Course::query()
            ->whereIn('id', $courseIds)
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get(['public_id', 'title', 'status', 'published_at'])
            ->map(fn (Course $c): array => [
                'id' => (string) $c->getAttribute('public_id'),
                'title' => (string) $c->getAttribute('title'),
                'status' => $c->getAttribute('status')->value,
                'occurred_at' => $c->getAttribute('published_at')?->toIso8601String(),
            ])->all();

        return [
            'recently_edited' => $recentlyEdited,
            'recently_published' => $recentlyPublished,
        ];
    }

    /**
     * Actionable alerts, each traceable to a real evaluation or column.
     *
     * BOUNDING. Readiness costs several queries per course, so evaluating an instructor's whole
     * catalogue would make the panel slower the more courses they own. At most
     * MAX_EVALUATED_COURSES are evaluated, ordered so the courses most likely to need attention
     * come first: drafts and unpublished work ahead of published courses, then most-recently
     * edited. The response reports `evaluated_count`, `total_count` and `truncated` so the panel
     * can say "showing the 50 most recently edited of 214" — it never presents a partial sweep as a
     * clean bill of health, which is the failure mode that matters here.
     *
     * The cheap alerts (stale drafts, courses without learners) are NOT bounded this way: they come
     * from columns and one grouped aggregate, so they stay complete across the whole scope.
     *
     * @param  list<int>  $courseIds
     * @return array<string, mixed>
     */
    public function alerts(array $courseIds): array
    {
        if ($courseIds === []) {
            return $this->emptyAlerts();
        }

        $columns = ['id', 'public_id', 'title', 'status', 'description', 'thumbnail_path', 'visibility', 'updated_at'];

        // Complete across the scope — these are column reads, not evaluations.
        $courses = Course::query()->whereIn('id', $courseIds)->get($columns);

        // Bounded slice for the expensive readiness sweep.
        $evaluable = Course::query()
            ->whereIn('id', $courseIds)
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [CourseStatus::Published->value])
            ->orderByDesc('updated_at')
            ->limit(self::MAX_EVALUATED_COURSES)
            ->get($columns);

        $withTrainer = CourseTrainer::query()
            ->whereIn('course_id', $evaluable->modelKeys())
            ->distinct()
            ->pluck('course_id')
            ->map(static fn ($id): int => (int) $id)
            ->flip()
            ->all();

        $blocked = [];
        $warned = [];

        foreach ($evaluable as $course) {
            $id = (int) $course->getKey();
            $report = $this->readiness->report(new CourseReadinessInput(
                courseId: $id,
                coursePublicId: (string) $course->getAttribute('public_id'),
                description: $course->getAttribute('description'),
                thumbnailPath: $course->getAttribute('thumbnail_path'),
                hasInstructor: isset($withTrainer[$id]),
                visibility: $course->getAttribute('visibility')?->value,
            ));

            $row = [
                'id' => (string) $course->getAttribute('public_id'),
                'title' => (string) $course->getAttribute('title'),
                'status' => $course->getAttribute('status')->value,
            ];

            if ($report->blockers() !== []) {
                $blocked[] = $row + [
                    'blocker_count' => count($report->blockers()),
                    // The first blocker's own words: the alert should say what is wrong, not merely
                    // that something is.
                    'first_blocker' => $report->firstBlockerReason(),
                ];
            }

            if ($report->warnings() !== []) {
                $warned[] = $row + ['warning_count' => count($report->warnings())];
            }
        }

        return [
            'publish_blockers' => $blocked,
            'warnings' => $warned,
            // Says plainly how much of the catalogue was actually looked at. Without this a
            // truncated sweep reads as "everything is fine".
            'readiness_coverage' => [
                'evaluated_count' => $evaluable->count(),
                'total_count' => count($courseIds),
                'truncated' => count($courseIds) > $evaluable->count(),
                'limit' => self::MAX_EVALUATED_COURSES,
            ],
            'stale_drafts' => $this->staleDrafts($courses),
            'courses_without_learners' => $this->coursesWithoutLearners($courses, $courseIds),
            'at_risk_learners' => [
                'available' => false,
                'reason' => 'At-risk learner detection is not configured.',
            ],
            'failed_publishes' => [
                'available' => false,
                'reason' => 'Failed publish attempts are not recorded.',
            ],
        ];
    }

    /**
     * PUBLISHED courses with no enrollments — a real signal that something is not reaching learners.
     *
     * Restricted to published courses on purpose: a draft having no learners is not a problem, it is
     * the definition of a draft, and alerting on it would be noise that trains authors to ignore
     * the panel.
     *
     * @param  Collection<int, Course>  $courses
     * @param  list<int>  $courseIds
     * @return list<array<string, mixed>>
     */
    private function coursesWithoutLearners(Collection $courses, array $courseIds): array
    {
        $stats = $this->enrollments->statsPerCourse($courseIds);

        return $courses
            ->filter(fn (Course $c): bool => $c->getAttribute('status') === CourseStatus::Published
                && ($stats[(int) $c->getKey()] ?? null)?->enrollments === 0)
            ->map(fn (Course $c): array => [
                'id' => (string) $c->getAttribute('public_id'),
                'title' => (string) $c->getAttribute('title'),
            ])
            ->values()
            ->all();
    }

    /**
     * Drafts nobody has touched in a month. A defensible threshold from a real column, not a
     * judgement about the author.
     *
     * @param  Collection<int, Course>  $courses
     * @return list<array<string, mixed>>
     */
    private function staleDrafts(Collection $courses): array
    {
        $cutoff = now()->subDays(self::STALE_DRAFT_DAYS);

        return $courses
            ->filter(fn (Course $c): bool => $c->getAttribute('status') === CourseStatus::Draft
                && $c->getAttribute('updated_at') !== null
                && $c->getAttribute('updated_at')->lt($cutoff))
            ->map(fn (Course $c): array => [
                'id' => (string) $c->getAttribute('public_id'),
                'title' => (string) $c->getAttribute('title'),
                'last_updated_at' => $c->getAttribute('updated_at')?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function emptyAlerts(): array
    {
        return [
            'publish_blockers' => [],
            'warnings' => [],
            'readiness_coverage' => [
                'evaluated_count' => 0,
                'total_count' => 0,
                'truncated' => false,
                'limit' => self::MAX_EVALUATED_COURSES,
            ],
            'stale_drafts' => [],
            'courses_without_learners' => [],
            'at_risk_learners' => [
                'available' => false,
                'reason' => 'At-risk learner detection is not configured.',
            ],
            'failed_publishes' => [
                'available' => false,
                'reason' => 'Failed publish attempts are not recorded.',
            ],
        ];
    }
}
