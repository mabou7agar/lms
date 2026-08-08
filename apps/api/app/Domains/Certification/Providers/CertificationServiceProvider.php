<?php

namespace App\Domains\Certification\Providers;

use App\Contexts\Learning\Events\CourseCompleted;
use App\Domains\Certification\Contracts\PdfGenerator;
use App\Domains\Certification\Listeners\GenerateCertificateOnCourseCompleted;
use App\Domains\Certification\Models\Badge;
use App\Domains\Certification\Models\Certificate;
use App\Domains\Certification\Pdf\PdfGeneratorManager;
use App\Domains\Certification\Policies\BadgePolicy;
use App\Domains\Certification\Policies\CertificatePolicy;
use App\Domains\Certification\Support\CertificateStatusAdapter;
use App\Platform\Shared\Certification\Contracts\CertificateStatusPort;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Wires the Certification module: config, migrations, routes, policies, the PdfGenerator binding
 * (Fake default, never Browsershot directly), and the CourseCompleted → certificate listener.
 */
class CertificationServiceProvider extends BaseDomainServiceProvider
{
    protected array $routeFiles = ['routes/certification.php'];

    /** @var array<class-string, class-string> */
    protected array $policies = [
        Certificate::class => CertificatePolicy::class,
        Badge::class => BadgePolicy::class,
    ];

    protected function domainPath(): string
    {
        return dirname(__DIR__);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/certification.php', 'certification');

        $this->app->bind(PdfGenerator::class, fn ($app) => $app->make(PdfGeneratorManager::class)->resolve());

        // Certification owns the certificates table; reporting surfaces in other contexts (the
        // instructor portal) read certificate status only through this port, never the model.
        $this->app->bind(CertificateStatusPort::class, CertificateStatusAdapter::class);
    }

    protected function bootDomain(): void
    {
        // Public verification is per-IP throttled to slow verification-code enumeration.
        RateLimiter::for('certification-verify', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));

        // Certificates are generated ONLY in response to Learning's CourseCompleted event.
        Event::listen(CourseCompleted::class, GenerateCertificateOnCourseCompleted::class);
    }
}
