<?php

namespace App\Contexts\Learning\Providers;

use App\Contexts\Learning\Adapters\CourseEnrollmentAdapter;
use App\Contexts\Learning\Adapters\MediaEnrollmentAdapter;
use App\Contexts\Learning\Analytics\EnrollmentStatsAdapter;
use App\Contexts\Learning\Analytics\ManagerLearningReport;
use App\Contexts\Learning\Analytics\WatchTimeAdapter;
use App\Contexts\Learning\Events\LessonProgressRecorded;
use App\Contexts\Learning\Listeners\UpdateLearningSession;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Policies\EnrollmentPolicy;
use App\Contexts\Learning\Support\NullAssessmentResultPort;
use App\Contexts\Learning\Support\NullAssignmentRequirementPort;
use App\Contexts\Learning\Support\NullCourseNavigationPort;
use App\Contexts\Learning\Support\NullLessonAvailabilityPort;
use App\Contexts\Learning\Support\NullLessonRequiredBlocksPort;
use App\Platform\Shared\Assessment\Contracts\AssessmentResultPort;
use App\Platform\Shared\Enterprise\Contracts\ManagerReportPort;
use App\Platform\Shared\Learning\Contracts\AssignmentRequirementPort;
use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;
use App\Platform\Shared\Learning\Contracts\CourseNavigationPort;
use App\Platform\Shared\Learning\Contracts\EnrollmentStatsPort;
use App\Platform\Shared\Learning\Contracts\LessonAvailabilityPort;
use App\Platform\Shared\Learning\Contracts\LessonRequiredBlocksPort;
use App\Platform\Shared\Learning\Contracts\WatchTimePort;
use App\Platform\Shared\Media\Contracts\MediaEnrollmentPort;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * Wires the Learning module: config, migrations, learner + runtime routes, the EnrollmentPolicy, the
 * progress→session listener, and Learning's cross-context port implementations. Media signing is
 * provided by the Media platform (PlaybackPort) and the lesson→asset lookup by Authoring
 * (MediaAssetPort); Learning consumes those and, in turn, publishes:
 *  - CourseEnrollmentPort  -> real Enrollment-backed adapter (Assessment consumes it).
 *  - MediaEnrollmentPort   -> rebinds Media's deny-by-default null with the real enrollment/
 *                             publication rule.
 *  - LessonAvailabilityPort / CourseNavigationPort / LessonRequiredBlocksPort -> null defaults
 *                             (drip/free-navigation/block-gating inert until a real provider binds).
 *  - AssignmentRequirementPort -> null default ONLY if the Assessment context did not already bind
 *                             its real adapter (bindIf, so Assessment's binding always wins).
 */
class LearningServiceProvider extends BaseDomainServiceProvider
{
    protected array $routeFiles = [
        'routes/learning.php',
        'routes/learning_runtime.php',
    ];

    /** @var array<class-string, class-string> */
    protected array $policies = [
        Enrollment::class => EnrollmentPolicy::class,
    ];

    protected function domainPath(): string
    {
        return dirname(__DIR__);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/learning.php', 'learning');

        // Learning owns enrollment aggregates; reporting surfaces in other contexts consume them
        // through this port instead of reading the enrollments table across a boundary.
        $this->app->bind(EnrollmentStatsPort::class, EnrollmentStatsAdapter::class);

        // Instructor watch-time / drop-off / per-learner drill-down read model. Learning owns the
        // video-progress, lesson-progress and learning-session tables; the instructor portal reads
        // them only through this port.
        $this->app->bind(WatchTimePort::class, WatchTimeAdapter::class);

        // Enterprise manager learning report consumed by the CRM enterprise portal through this Shared
        // port. Learning owns the enrollment/progress tables and reads certificates + assessment
        // outcomes via their own Shared ports, so no cross-context model is imported.
        $this->app->bind(ManagerReportPort::class, ManagerLearningReport::class);

        // Enrollment surface published to other contexts (Assessment: roster + entitlement).
        $this->app->bind(CourseEnrollmentPort::class, CourseEnrollmentAdapter::class);

        // Learning owns enrollment/publication, so it provides the real course-media access rule,
        // overriding the Media platform's deny-by-default NullMediaEnrollmentPort.
        $this->app->bind(MediaEnrollmentPort::class, MediaEnrollmentAdapter::class);

        // Cross-context ports Learning DECLARES and consumes for completion/sequencing. No real
        // provider is bound yet, so the null defaults keep behaviour inert (available immediately,
        // strict prerequisites, no block gate).
        $this->app->bind(LessonAvailabilityPort::class, NullLessonAvailabilityPort::class);
        $this->app->bind(CourseNavigationPort::class, NullCourseNavigationPort::class);
        $this->app->bind(LessonRequiredBlocksPort::class, NullLessonRequiredBlocksPort::class);

        // Assessment (registered earlier) binds the real AssignmentRequirementAdapter; bindIf keeps
        // that binding and only falls back to the completion-safe null when Assessment is absent.
        $this->app->bindIf(AssignmentRequirementPort::class, NullAssignmentRequirementPort::class);

        // Same pattern for the learner-result port consumed by CourseCompletionEvaluator: Assessment
        // binds the real adapter, and this completion-safe null only applies when Assessment is absent,
        // so the default-policy path never hard-depends on the Assessment context.
        $this->app->bindIf(AssessmentResultPort::class, NullAssessmentResultPort::class);
    }

    protected function bootDomain(): void
    {
        Event::listen(LessonProgressRecorded::class, UpdateLearningSession::class);
    }
}
