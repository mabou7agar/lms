<?php

namespace App\Domains\Reviews\Providers;

use App\Domains\Reviews\Models\CourseReview;
use App\Domains\Reviews\Policies\CourseReviewPolicy;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;

/**
 * Wires the Reviews module: its route file, its policy, and its migrations — all by the
 * BaseDomainServiceProvider convention, exactly like AuthoringServiceProvider.
 *
 * INTEGRATOR: register App\Domains\Reviews\Providers\ReviewsServiceProvider in bootstrap/providers.php
 * (after CatalogServiceProvider / AuthoringServiceProvider, so the authoring.manage-curriculum gate
 * that CourseAccessPort delegates to is already defined). The shared moderation substrate this
 * domain depends on is wired separately by ModerationServiceProvider.
 */
class ReviewsServiceProvider extends BaseDomainServiceProvider
{
    /** @var list<string> */
    protected array $routeFiles = [
        'routes/reviews.php',
    ];

    /** @var array<class-string, class-string> */
    protected array $policies = [
        CourseReview::class => CourseReviewPolicy::class,
    ];

    protected function domainPath(): string
    {
        return dirname(__DIR__);
    }
}
