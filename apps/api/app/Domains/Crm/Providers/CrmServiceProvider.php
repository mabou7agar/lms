<?php

namespace App\Domains\Crm\Providers;

use App\Domains\Crm\Events\ConsultingRequestCreated;
use App\Domains\Crm\Events\LeadCreated;
use App\Domains\Crm\Listeners\LogConsultingRequestActivity;
use App\Domains\Crm\Listeners\LogLeadCreatedActivity;
use App\Domains\Crm\Models\ConsultingRequest;
use App\Domains\Crm\Models\CrmTask;
use App\Domains\Crm\Models\Department;
use App\Domains\Crm\Models\Lead;
use App\Domains\Crm\Models\Opportunity;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Models\Team;
use App\Domains\Crm\Policies\ConsultingRequestPolicy;
use App\Domains\Crm\Policies\CrmTaskPolicy;
use App\Domains\Crm\Policies\DepartmentPolicy;
use App\Domains\Crm\Policies\LeadPolicy;
use App\Domains\Crm\Policies\OpportunityPolicy;
use App\Domains\Crm\Policies\OrganizationMemberPolicy;
use App\Domains\Crm\Policies\OrganizationPolicy;
use App\Domains\Crm\Policies\TeamPolicy;
use App\Domains\Crm\Ports\CrmMarketingAudienceAdapter;
use App\Domains\Crm\Ports\OrgManagerCheckAdapter;
use App\Domains\Crm\Ports\SeatProvisioningAdapter;
use App\Platform\Shared\Enterprise\Contracts\OrgManagerCheckPort;
use App\Platform\Shared\Marketing\Contracts\MarketingAudiencePort;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;
use App\Platform\Shared\Seats\Contracts\SeatProvisioningPort;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Wires the CRM module: config, migrations, routes, policies, rate limiters, and activity-logging
 * listeners. CRM depends only on Identity — never Learning or Commerce.
 */
class CrmServiceProvider extends BaseDomainServiceProvider
{
    protected array $routeFiles = ['routes/crm.php', 'routes/crm_public.php', 'routes/enterprise.php'];

    /** @var array<class-string, class-string> */
    protected array $policies = [
        Organization::class => OrganizationPolicy::class,
        Lead::class => LeadPolicy::class,
        Opportunity::class => OpportunityPolicy::class,
        CrmTask::class => CrmTaskPolicy::class,
        ConsultingRequest::class => ConsultingRequestPolicy::class,
        OrganizationMember::class => OrganizationMemberPolicy::class,
        Department::class => DepartmentPolicy::class,
        Team::class => TeamPolicy::class,
    ];

    protected function domainPath(): string
    {
        return dirname(__DIR__);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/crm.php', 'crm');

        // CRM owns the seat infrastructure (seat_pools / seat_assignments / SeatService) and
        // implements the Shared SeatProvisioningPort so Commerce can drive organization seats
        // without importing a single CRM model. This is the single Commerce→CRM seam for seats.
        $this->app->bind(SeatProvisioningPort::class, SeatProvisioningAdapter::class);

        // Marketing audience seam: CRM resolves lead recipients + live marketing consent for the
        // Notifications marketing engine through the Shared MarketingAudiencePort — no CRM model ever
        // crosses into Notifications. CRM registers before Notifications, so this binding wins over
        // the Null default Notifications falls back to when CRM is absent.
        $this->app->bind(MarketingAudiencePort::class, CrmMarketingAudienceAdapter::class);

        // Enterprise manager capability seam: Identity's profile payload exposes an `is_org_manager`
        // UI hint for the manager-portal route guard through this Shared port, so Identity never imports
        // a CRM model. Authority itself stays with OrganizationMemberPolicy / ManagerScope.
        $this->app->bind(OrgManagerCheckPort::class, OrgManagerCheckAdapter::class);
    }

    protected function bootDomain(): void
    {
        Event::listen(LeadCreated::class, LogLeadCreatedActivity::class);
        Event::listen(ConsultingRequestCreated::class, LogConsultingRequestActivity::class);

        // Public enterprise-lead intake: throttle guest writes by IP + submitted email so a single
        // origin cannot flood the pipeline. Sized from config (default 10/min).
        RateLimiter::for('crm-public-lead', static function (Request $request): Limit {
            $perMinute = (int) config('crm.public_lead.rate_limit_per_minute', 10);
            $email = mb_strtolower(trim((string) $request->input('work_email', '')));

            return Limit::perMinute($perMinute)->by($request->ip().'|'.$email);
        });
    }
}
