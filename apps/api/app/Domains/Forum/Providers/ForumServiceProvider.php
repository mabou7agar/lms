<?php

declare(strict_types=1);

namespace App\Domains\Forum\Providers;

use App\Domains\Forum\Models\ForumPost;
use App\Domains\Forum\Models\ForumThread;
use App\Domains\Forum\Policies\ForumPostPolicy;
use App\Domains\Forum\Policies\ForumThreadPolicy;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;

/**
 * Wires the Forum module: its migrations, the forum route file, and the thread/post policies. It
 * declares no cross-context container bindings — participation and instructor authority are read
 * through existing Shared / Identity ports (CourseEnrollmentPort, CourseAccessPort, CurriculumReadPort,
 * UserLookupPort). Register this provider in bootstrap/providers.php during integration.
 */
class ForumServiceProvider extends BaseDomainServiceProvider
{
    /** @var list<string> */
    protected array $routeFiles = [
        'routes/forum.php',
    ];

    /** @var array<class-string, class-string> */
    protected array $policies = [
        ForumThread::class => ForumThreadPolicy::class,
        ForumPost::class => ForumPostPolicy::class,
    ];

    protected function domainPath(): string
    {
        return dirname(__DIR__);
    }
}
