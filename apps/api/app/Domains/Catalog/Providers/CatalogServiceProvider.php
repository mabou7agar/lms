<?php

namespace App\Domains\Catalog\Providers;

use App\Domains\Catalog\Access\CourseAccessAdapter;
use App\Domains\Catalog\Console\Commands\PublishScheduledCoursesCommand;
use App\Domains\Catalog\Contracts\CoursePublishGuard;
use App\Domains\Catalog\Contracts\NullCoursePublishGuard;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Models\CourseAnnouncement;
use App\Domains\Catalog\Policies\CategoryPolicy;
use App\Domains\Catalog\Policies\CourseAnnouncementPolicy;
use App\Domains\Catalog\Policies\CoursePolicy;
/**
 * Wires the Catalog module: config, migrations, public routes, policies, and the default
 * CoursePublishGuard binding (a downstream domain may override it later).
 */
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Curriculum\Adapters\NullCurriculumForkPort;
use App\Platform\Shared\Curriculum\Contracts\CurriculumForkPort;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;

class CatalogServiceProvider extends BaseDomainServiceProvider
{
    protected array $routeFiles = ['routes/catalog.php', 'routes/teach.php'];

    /** @var array<class-string, class-string> */
    protected array $policies = [
        Course::class => CoursePolicy::class,
        Category::class => CategoryPolicy::class,
        CourseAnnouncement::class => CourseAnnouncementPolicy::class,
    ];

    protected function domainPath(): string
    {
        return dirname(__DIR__);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/catalog.php', 'catalog');

        // Default publish guard; standalone Catalog always allows publishing.
        $this->app->bind(CoursePublishGuard::class, NullCoursePublishGuard::class);

        // Default curriculum fork: a no-op so a course can always be duplicated. Authoring overrides
        // this with the real snapshot-fork adapter when loaded (it loads after Catalog).
        $this->app->bind(CurriculumForkPort::class, NullCurriculumForkPort::class);

        // Catalog owns Course, so it answers course-ownership questions on behalf of contexts that
        // may not import the model (Assessment). The adapter delegates to the existing
        // authoring.manage-curriculum gate rather than restating the rule.
        $this->app->bind(CourseAccessPort::class, CourseAccessAdapter::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PublishScheduledCoursesCommand::class]);
        }
    }
}
