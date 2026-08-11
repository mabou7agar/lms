<?php

namespace App\Platform\Branding\Providers;

use App\Platform\Branding\Models\CustomDomain;
use App\Platform\Branding\Models\OrganizationBrandSetting;
use App\Platform\Branding\Policies\CustomDomainPolicy;
use App\Platform\Branding\Policies\OrganizationBrandPolicy;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;

/**
 * Wires the Branding / white-label module: loads its migrations, the branding route file (public
 * host-resolved endpoint + org-admin brand/domain endpoints) and the per-org policies. A small,
 * self-contained Platform module — depends only on the Shared kernel and Identity CONTRACTS (the
 * Actor port used by the policies), never on CRM/other-context models. The GLOBAL brand editor lives
 * in this module's Filament/Resources (auto-discovered by the panel); per-org branding is API-managed.
 */
class BrandingServiceProvider extends BaseDomainServiceProvider
{
    /** @var array<int, string> */
    protected array $routeFiles = ['routes/branding.php'];

    /** @var array<class-string, class-string> */
    protected array $policies = [
        OrganizationBrandSetting::class => OrganizationBrandPolicy::class,
        CustomDomain::class => CustomDomainPolicy::class,
    ];

    protected function domainPath(): string
    {
        return dirname(__DIR__);
    }
}
