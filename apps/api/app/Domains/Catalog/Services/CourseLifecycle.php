<?php

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Actions\Course\ArchiveCourseAction;
use App\Domains\Catalog\Actions\Course\PublishCourseAction;
use App\Domains\Catalog\Actions\Course\UnpublishCourseAction;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Exceptions\CourseTransitionException;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Audit\AuditLogger;
use DateTimeInterface;

/**
 * The course publishing lifecycle as an explicit state machine. Every status change flows through
 * transition(): it (a) rejects moves the legal-transition map forbids, (b) applies the change —
 * routing Publish/Unpublish/Archive through their existing Actions so event dispatch and
 * published_at/last_published_at behaviour is preserved, (c) forces a ->Published transition through
 * the existing CoursePublishGuard (readiness) via PublishCourseAction, so an unready course still
 * cannot publish by any path, and (d) writes one audit entry per successful transition.
 *
 * This is the ONLY place course status may change. The raw Filament Select that bypassed the guard
 * is gone; the instructor API and admin panel both call here.
 */
class CourseLifecycle
{
    /**
     * The legal-transition map, keyed by the current status value. A move is permitted only if the
     * target appears in the current status's list. Deliberately conservative: Published may only be
     * withdrawn (Unpublished) or Archived, and Archived may only be restored to Draft.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'draft' => ['review', 'published', 'scheduled', 'archived'],
        'review' => ['approved', 'draft', 'archived'],
        'approved' => ['published', 'scheduled', 'draft', 'archived'],
        'scheduled' => ['published', 'draft', 'archived'],
        'published' => ['unpublished', 'archived'],
        'unpublished' => ['published', 'draft', 'archived'],
        'archived' => ['draft'],
    ];

    public function __construct(
        private readonly PublishCourseAction $publish,
        private readonly UnpublishCourseAction $unpublish,
        private readonly ArchiveCourseAction $archive,
        private readonly AuditLogger $audit,
    ) {}

    /** Whether the state machine permits moving a course from $from to $to. */
    public function canTransition(CourseStatus $from, CourseStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * The statuses a course in $from may legally move to.
     *
     * @return list<CourseStatus>
     */
    public function allowedTransitions(CourseStatus $from): array
    {
        return array_map(
            static fn (string $value): CourseStatus => CourseStatus::from($value),
            self::TRANSITIONS[$from->value] ?? [],
        );
    }

    /**
     * Move a course to a new status, or throw. Illegal moves raise CourseTransitionException; an
     * unready ->Published move raises CoursePublishBlockedException (from the guard) and leaves the
     * course untouched. A Scheduled move requires a future $scheduledPublishAt.
     */
    public function transition(
        Course $course,
        CourseStatus $to,
        ?Actor $actor = null,
        ?DateTimeInterface $scheduledPublishAt = null,
    ): Course {
        $from = $course->status;

        if (! $this->canTransition($from, $to)) {
            throw CourseTransitionException::illegal($from, $to);
        }

        // Publish/Unpublish/Archive keep their own transaction, event dispatch and timestamp writes.
        // The remaining states (Draft, Review, Approved, Scheduled) are plain status writes.
        $course = match ($to) {
            CourseStatus::Published => $this->publish->execute($course),
            CourseStatus::Unpublished => $this->unpublish->execute($course),
            CourseStatus::Archived => $this->archive->execute($course),
            CourseStatus::Scheduled => $this->applySchedule($course, $scheduledPublishAt),
            default => $this->applyStatus($course, $to),
        };

        $this->audit->log('catalog.course.transitioned', $course, [
            'from' => $from->value,
            'to' => $to->value,
        ], $actor?->actorId());

        return $course;
    }

    /** Set status = Scheduled and stamp the future publish time the scheduler will act on. */
    private function applySchedule(Course $course, ?DateTimeInterface $scheduledPublishAt): Course
    {
        if ($scheduledPublishAt === null || $scheduledPublishAt->getTimestamp() <= now()->getTimestamp()) {
            throw CourseTransitionException::scheduleRequiresFutureTime();
        }

        $course->forceFill([
            'status' => CourseStatus::Scheduled->value,
            'scheduled_publish_at' => $scheduledPublishAt,
        ])->save();

        return $course;
    }

    /** Plain status write for the non-terminal editorial states (Draft, Review, Approved). */
    private function applyStatus(Course $course, CourseStatus $to): Course
    {
        $attributes = ['status' => $to->value];

        // Leaving Scheduled for any non-published state clears the pending publish time so the
        // scheduler never resurrects it.
        if ($course->status === CourseStatus::Scheduled) {
            $attributes['scheduled_publish_at'] = null;
        }

        $course->forceFill($attributes)->save();

        return $course;
    }
}
