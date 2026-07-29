<?php

namespace App\Providers;

use App\Filament\Widgets\PlatformOverview;
use App\Platform\Identity\Http\Middleware\EnforceAdminMfa;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Company-operated admin panel at /admin (HElbaron).
 *
 * Access is gated by User::canAccessPanel() (active + super_admin/admin) and, when
 * ADMIN_REQUIRE_MFA is enabled, by EnforceAdminMfa. Resources are auto-discovered from every
 * domain's Filament/Resources directory; no business logic lives in the panel — resources read
 * existing models and defer mutations to domain Actions/Services.
 */
class AdminPanelProvider extends PanelProvider
{
    /**
     * Domains whose Filament/Resources are auto-discovered into the panel, in navigation order.
     *
     * @var array<string, string>
     */
    private const RESOURCE_PATHS = [
        'App\\Platform\\Identity\\Filament\\Resources' => 'Platform/Identity/Filament/Resources',
        'App\\Platform\\Notifications\\Filament\\Resources' => 'Platform/Notifications/Filament/Resources',
        'App\\Platform\\Homepage\\Filament\\Resources' => 'Platform/Homepage/Filament/Resources',
        'App\\Platform\\Branding\\Filament\\Resources' => 'Platform/Branding/Filament/Resources',
        'App\\Platform\\Navigation\\Filament\\Resources' => 'Platform/Navigation/Filament/Resources',
        'App\\Platform\\Pages\\Filament\\Resources' => 'Platform/Pages/Filament/Resources',
        'App\\Platform\\Features\\Filament\\Resources' => 'Platform/Features/Filament/Resources',
        'App\\Platform\\Seo\\Filament\\Resources' => 'Platform/Seo/Filament/Resources',
        'App\\Contexts\\Learning\\Filament\\Resources' => 'Contexts/Learning/Filament/Resources',
        'App\\Contexts\\Commerce\\Filament\\Resources' => 'Contexts/Commerce/Filament/Resources',
        'App\\Contexts\\Analytics\\Filament\\Resources' => 'Contexts/Analytics/Filament/Resources',
        'App\\Domains\\Catalog\\Filament\\Resources' => 'Domains/Catalog/Filament/Resources',
        'App\\Domains\\Authoring\\Filament\\Resources' => 'Domains/Authoring/Filament/Resources',
        'App\\Domains\\Certification\\Filament\\Resources' => 'Domains/Certification/Filament/Resources',
        'App\\Domains\\Live\\Filament\\Resources' => 'Domains/Live/Filament/Resources',
        'App\\Domains\\Crm\\Filament\\Resources' => 'Domains/Crm/Filament/Resources',
        'App\\Platform\\Shared\\Filament\\Resources' => 'Platform/Shared/Filament/Resources',
    ];

    public function panel(Panel $panel): Panel
    {
        $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->authGuard('web')
            ->brandName('HElbaron')
            ->login()
            ->navigationGroups([
                'Identity',
                'Catalog',
                'Authoring',
                'Learning',
                'Commerce',
                'Certification',
                'Live',
                'CRM',
                'Analytics',
                'Notifications',
                'Branding',
                'Navigation',
                'System',
            ])
            ->pages([Dashboard::class])
            ->widgets([PlatformOverview::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnforceAdminMfa::class,
            ]);

        foreach (self::RESOURCE_PATHS as $namespace => $path) {
            $panel->discoverResources(in: app_path($path), for: $namespace);
        }

        // Sprint 4 (H5): notification delivery health widget. Discovered by path/namespace string —
        // NOT a class import — so the composition root gains no compile-time dependency on the
        // Notifications context (keeps Deptrac boundaries intact).
        $panel->discoverWidgets(
            in: app_path('Platform/Notifications/Filament/Widgets'),
            for: 'App\\Platform\\Notifications\\Filament\\Widgets',
        );

        return $panel;
    }
}
