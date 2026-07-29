<?php

use App\Contexts\Analytics\Providers\AnalyticsServiceProvider;
use App\Contexts\Commerce\Providers\CommerceServiceProvider;
use App\Contexts\Learning\Providers\LearningServiceProvider;
use App\Domains\Assessment\Providers\AssessmentServiceProvider;
use App\Domains\Authoring\Providers\AuthoringServiceProvider;
use App\Domains\Catalog\Providers\CatalogServiceProvider;
use App\Domains\Certification\Providers\CertificationServiceProvider;
use App\Domains\Crm\Providers\CrmServiceProvider;
use App\Domains\Live\Providers\LiveServiceProvider;
use App\Platform\Branding\Providers\BrandingServiceProvider;
use App\Platform\Features\Providers\FeaturesServiceProvider;
use App\Platform\Homepage\Providers\HomepageServiceProvider;
use App\Platform\Identity\Providers\IdentityServiceProvider;
use App\Platform\Media\Providers\MediaServiceProvider;
use App\Platform\Navigation\Providers\NavigationServiceProvider;
use App\Platform\Notifications\Providers\NotificationsServiceProvider;
use App\Platform\Pages\Providers\PagesServiceProvider;
use App\Platform\Seo\Providers\SeoServiceProvider;
use App\Platform\Shared\Providers\SharedServiceProvider;
use App\Providers\AdminPanelProvider;
use App\Providers\AppServiceProvider;

/*
 | Provider registration order matters: the Shared foundation loads first, then Identity as
 | the shared kernel, then the remaining domains; Analytics and Notifications load last as
 | event consumers. The Filament admin panel loads last so resource discovery sees every domain.
 */
return [
    AppServiceProvider::class,

    SharedServiceProvider::class,
    MediaServiceProvider::class,

    IdentityServiceProvider::class,
    CatalogServiceProvider::class,
    AuthoringServiceProvider::class,
    // After Authoring: the assessment.manage-assessment gate delegates to authoring.manage-curriculum.
    AssessmentServiceProvider::class,
    LearningServiceProvider::class,
    CommerceServiceProvider::class,
    CertificationServiceProvider::class,
    LiveServiceProvider::class,
    CrmServiceProvider::class,
    AnalyticsServiceProvider::class,
    NotificationsServiceProvider::class,
    HomepageServiceProvider::class,
    BrandingServiceProvider::class,
    NavigationServiceProvider::class,
    PagesServiceProvider::class,
    FeaturesServiceProvider::class,
    SeoServiceProvider::class,

    // Filament admin panel (/admin).
    AdminPanelProvider::class,
];
