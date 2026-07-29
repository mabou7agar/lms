<?php

use App\Contexts\Learning\Support\NullAssignmentRequirementPort;
use App\Contexts\Learning\Support\NullCourseNavigationPort;
use App\Contexts\Learning\Support\NullLessonAvailabilityPort;
use App\Contexts\Learning\Support\NullLessonRequiredBlocksPort;
use App\Platform\Shared\Learning\Contracts\AssignmentRequirementPort;
use App\Platform\Shared\Learning\Contracts\CourseNavigationPort;
use App\Platform\Shared\Learning\Contracts\LessonAvailabilityPort;
use App\Platform\Shared\Learning\Contracts\LessonRequiredBlocksPort;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Route;

/**
 * Test-only wiring for the learner runtime. Mirrors what the integrator does in
 * LearningServiceProvider (bind the four runtime ports to their Null defaults) and registers the
 * additive runtime route file, so these feature tests are self-contained and pass whether or not
 * the shared wiring has been merged yet. Individual tests override a binding with a Fake* below.
 */
function bootLearningRuntime(): void
{
    app()->bind(AssignmentRequirementPort::class, NullAssignmentRequirementPort::class);
    app()->bind(LessonAvailabilityPort::class, NullLessonAvailabilityPort::class);
    app()->bind(CourseNavigationPort::class, NullCourseNavigationPort::class);
    app()->bind(LessonRequiredBlocksPort::class, NullLessonRequiredBlocksPort::class);

    if (! app('router')->has('learning-runtime.marker')) {
        Route::middleware('api')->prefix('api')->group(function (): void {
            require base_path('app/Contexts/Learning/routes/learning_runtime.php');
            Route::get('__learning_runtime_marker', fn () => null)->name('learning-runtime.marker');
        });
        app('router')->getRoutes()->refreshNameLookups();
    }
}

/** A configurable AssignmentRequirementPort for the completion-rule tests. */
final class FakeAssignmentRequirementPort implements AssignmentRequirementPort
{
    /** @var array<int, bool> */
    public array $required = [];

    /** @var array<int, bool> */
    public array $satisfied = [];

    public function hasRequired(int $lessonId): bool
    {
        return $this->required[$lessonId] ?? false;
    }

    public function requiredSatisfied(int $lessonId, int $userId): bool
    {
        return $this->satisfied[$lessonId] ?? false;
    }
}

/** A configurable drip schedule keyed by lesson id. */
final class FakeLessonAvailabilityPort implements LessonAvailabilityPort
{
    /** @var array<int, CarbonInterface> */
    public array $releaseAt = [];

    /**
     * @param  list<int>  $lessonIds
     * @return array<int, ?CarbonInterface>
     */
    public function releaseAtForLessons(array $lessonIds, CarbonInterface $enrolledAt): array
    {
        $out = [];
        foreach ($lessonIds as $id) {
            $out[$id] = $this->releaseAt[$id] ?? null;
        }

        return $out;
    }
}

/** A configurable free-navigation setting keyed by course id. */
final class FakeCourseNavigationPort implements CourseNavigationPort
{
    /** @var array<int, bool> */
    public array $free = [];

    public function allowsFreeNavigation(int $courseId): bool
    {
        return $this->free[$courseId] ?? false;
    }
}

/** A configurable required-blocks map keyed by lesson id. */
final class FakeLessonRequiredBlocksPort implements LessonRequiredBlocksPort
{
    /** @var array<int, list<string>> */
    public array $blocks = [];

    /** @return list<string> */
    public function requiredBlockIds(int $lessonId): array
    {
        return $this->blocks[$lessonId] ?? [];
    }
}
