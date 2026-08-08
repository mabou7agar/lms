<?php

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Exceptions\CoursePublishBlockedException;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Services\CourseLifecycle;
use Illuminate\Console\Command;

/**
 * The scheduled consumer for course scheduling. Every minute it finds Scheduled courses whose
 * scheduled_publish_at has arrived and publishes each through the CourseLifecycle state machine —
 * which routes the publish through the readiness guard. A course that is due but not yet ready is
 * left Scheduled (never silently corrupted), so it will be retried on the next tick once fixed.
 */
class PublishScheduledCoursesCommand extends Command
{
    protected $signature = 'courses:publish-scheduled';

    protected $description = 'Publish scheduled courses whose publish time has arrived and that pass readiness';

    public function handle(CourseLifecycle $lifecycle): int
    {
        $published = 0;
        $skipped = 0;

        Course::query()->scheduledDue()->orderBy('scheduled_publish_at')->get()
            ->each(function (Course $course) use ($lifecycle, &$published, &$skipped): void {
                try {
                    $lifecycle->transition($course, CourseStatus::Published);
                    $published++;
                } catch (CoursePublishBlockedException) {
                    // Not ready yet — leave it Scheduled and try again on the next tick.
                    $skipped++;
                }
            });

        $this->info("{$published} scheduled course(s) published, {$skipped} left pending (not ready).");

        return self::SUCCESS;
    }
}
