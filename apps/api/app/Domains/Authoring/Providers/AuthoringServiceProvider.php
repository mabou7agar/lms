<?php

namespace App\Domains\Authoring\Providers;

use App\Domains\Authoring\Curriculum\CurriculumReadAdapter;
use App\Domains\Authoring\Curriculum\SnapshotCurriculumForkAdapter;
use App\Domains\Authoring\Enums\AuthoringPermission;
use App\Domains\Authoring\Media\LessonMediaAssetPort;
use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\ContentVersion;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Module;
use App\Domains\Authoring\Models\Section;
use App\Domains\Authoring\Policies\BlockPolicy;
use App\Domains\Authoring\Policies\ContentVersionPolicy;
use App\Domains\Authoring\Policies\LessonPolicy;
use App\Domains\Authoring\Policies\ModulePolicy;
use App\Domains\Authoring\Policies\SectionPolicy;
use App\Domains\Authoring\Search\LessonIndexableContentAdapter;
use App\Domains\Authoring\Services\CurriculumPublishGuard;
use App\Domains\Authoring\Support\CourseTenantVisibility;
use App\Domains\Catalog\Contracts\CoursePublishGuard;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Curriculum\Contracts\CurriculumForkPort;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Media\Contracts\MediaAssetPort;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * Wires the Authoring module and, crucially, binds Catalog's CoursePublishGuard to Authoring's
 * CurriculumPublishGuard — so publishing a course now validates its curriculum. This overrides
 * Catalog's default NullCoursePublishGuard binding (Authoring loads after Catalog).
 */
class AuthoringServiceProvider extends BaseDomainServiceProvider
{
    protected array $routeFiles = [
        'routes/authoring_admin.php',
        'routes/versioning_admin.php',
        'routes/course_resources.php',
    ];

    /** @var array<class-string, class-string> */
    protected array $policies = [
        Section::class => SectionPolicy::class,
        Lesson::class => LessonPolicy::class,
        // P2/W02: block/module authorization resolves to the same authoring.manage-curriculum gate
        // via the parent course, so any future block/module write path is guarded from day one.
        Block::class => BlockPolicy::class,
        Module::class => ModulePolicy::class,
        // P2/W03: content-version authorization (course-scoped via CourseAccessPort).
        ContentVersion::class => ContentVersionPolicy::class,
    ];

    protected function domainPath(): string
    {
        return dirname(__DIR__);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/authoring.php', 'authoring');

        // Inversion of control: curriculum validity now governs course publishing.
        $this->app->bind(CoursePublishGuard::class, CurriculumPublishGuard::class);

        // Authoring owns lesson media metadata; expose it to other contexts as a MediaAssetRef.
        $this->app->bind(MediaAssetPort::class, LessonMediaAssetPort::class);

        // Temporary Phase-1 curriculum read projection (enrollability + resource DTO mappers).
        $this->app->bind(CurriculumReadPort::class, CurriculumReadAdapter::class);

        // Curriculum fork: overrides Catalog's NullCurriculumForkPort so duplicating a course also
        // materialises its curriculum (via the snapshot fork mechanism). Catalog stays decoupled —
        // it only depends on the Shared contract.
        $this->app->bind(CurriculumForkPort::class, SnapshotCurriculumForkAdapter::class);

        // Search: expose published lesson text to the RAG index (authenticated-audience knowledge).
        $this->app->tag([LessonIndexableContentAdapter::class], 'search.indexers');
    }

    protected function bootDomain(): void
    {
        // The single source of truth for "may this actor manage this course's curriculum".
        // Every section/lesson policy resolves to this gate via the parent course, so ownership
        // logic lives in exactly one place.
        //
        //   1. super_admin              — global bypass (preserved).
        //   2. authoring.curriculum.manage permission — admin-level global access (preserved).
        //   3. assigned trainer         — instructor scoped to courses they train, and only while
        //                                 the course is not archived (business rule).
        Gate::define('authoring.manage-curriculum', function (Actor $user, Course $course): bool {
            // super_admin is a genuine bypass, not a workaround: the seeders grant it no
            // permissions at all, by design, so it can only be recognised by role.
            //
            // `admin` is NO LONGER role-checked here. It was, because can() is guard-sensitive and
            // answered false under Sanctum for a genuine holder; hasPermission() pins the `web`
            // guard, so admin now flows through the permission it actually holds
            // (AuthoringSeeder grants authoring.curriculum.manage to admin). Anyone else granted
            // that permission gets the same access, which is what the grant is supposed to mean.
            if ($user->hasRole('super_admin') || $user->hasPermission(AuthoringPermission::ManageCurriculum->value)) {
                return true;
            }

            // T1 (Option N — "global-OR-own-org"): a scoped (non-bypass) actor may reach a GLOBAL
            // course or its OWN org-private course, but NEVER another organization's private course.
            // Enforced here, the single curriculum-authorization choke point, so every
            // section/lesson/block/module/content-version read, mutation, duplication, reorder,
            // versioning (snapshot/restore/clone/fork) and preview path inherits the tenant boundary
            // TRANSITIVELY through the parent course — no redundant tenant column on the child tables.
            // A no-op when no tenant resolves (matches SharedOrOwnedTenantScope), so the existing
            // NULL-org suite is behaviourally unchanged. A private course of org2 is thus invisible
            // to org1 exactly as an unowned course is.
            if (! CourseTenantVisibility::visible($course->getAttribute('organization_id'))) {
                return false;
            }

            return ! $course->isArchived() && $course->isTrainedBy($user->actorId());
        });
    }
}
