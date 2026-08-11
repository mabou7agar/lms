<?php

namespace App\Platform\Identity\Providers;

use App\Platform\Identity\Adapters\CurrentUserAdapter;
use App\Platform\Identity\Adapters\UserLookupAdapter;
use App\Platform\Identity\Adapters\UserPermissionAdapter;
use App\Platform\Identity\Adapters\UserRoleAdapter;
use App\Platform\Identity\Console\Commands\ExportOpenApiCommand;
use App\Platform\Identity\Contracts\CurrentUserPort;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Identity\Contracts\UserPermissionPort;
use App\Platform\Identity\Contracts\UserRolePort;
use App\Platform\Identity\Events\UserLoggedIn;
use App\Platform\Identity\Events\UserRegistered;
use App\Platform\Identity\Exceptions\ProtectedRoleException;
use App\Platform\Identity\Http\Controllers\LeaveImpersonationController;
use App\Platform\Identity\Listeners\SendEmailOtpOnRegistration;
use App\Platform\Identity\Listeners\SendPhoneOtpOnRegistration;
use App\Platform\Identity\Listeners\UpdateLastLoginTimestamp;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Models\UserDevice;
use App\Platform\Identity\Policies\DevicePolicy;
use App\Platform\Identity\Policies\RolePolicy;
use App\Platform\Identity\Policies\UserPolicy;
use App\Platform\Identity\Services\ImpersonationManager;
use App\Platform\Identity\Tenancy\RoleBasedTenancyBypassPolicy;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;
use App\Platform\Shared\Tenancy\TenancyBypassPolicy;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;

/**
 * Wires the Identity module: config, migrations, split route files, policies, named rate
 * limiters, and event→listener bindings. Registered in bootstrap/providers.php.
 */
class IdentityServiceProvider extends BaseDomainServiceProvider
{
    protected array $routeFiles = ['routes/auth.php', 'routes/social.php', 'routes/profile.php', 'routes/devices.php', 'routes/privacy.php', 'routes/developer.php'];

    protected function domainPath(): string
    {
        return dirname(__DIR__);
    }

    /** @var array<class-string, class-string> */
    protected array $policies = [
        User::class => UserPolicy::class,
        UserDevice::class => DevicePolicy::class,
        Role::class => RolePolicy::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/identity.php', 'identity');
        $this->mergeConfigFrom(__DIR__.'/../../../../config/sso.php', 'sso');

        // Identity owns RBAC, so it provides the concrete tenancy-bypass policy (platform admins
        // bypass tenant scoping). This overrides the Shared NullTenancyBypassPolicy default.
        $this->app->bind(TenancyBypassPolicy::class, RoleBasedTenancyBypassPolicy::class);

        // IdentityContracts ports → Identity adapters. Identity is the ONLY layer that binds these
        // (it alone may touch the User model). Consumers depend on the interfaces, not the adapters.
        $this->app->bind(CurrentUserPort::class, CurrentUserAdapter::class);
        $this->app->bind(UserLookupPort::class, UserLookupAdapter::class);
        $this->app->bind(UserPermissionPort::class, UserPermissionAdapter::class);
        $this->app->bind(UserRolePort::class, UserRoleAdapter::class);

        // Public-API OpenAPI export. Registered explicitly (Identity has no auto-discovered
        // console namespace) so `php artisan identity:openapi-export` is available.
        $this->commands([ExportOpenApiCommand::class]);
    }

    protected function bootDomain(): void
    {
        $this->registerRateLimiters();
        $this->registerListeners();
        $this->protectSystemRoles();
        $this->registerImpersonation();
    }

    /**
     * Wires impersonation into the admin panel: the "leave" endpoint (web + auth, so the
     * impersonated session — not the API guard — is what ends it) and the always-visible banner
     * that renders only while an impersonation session is active.
     */
    private function registerImpersonation(): void
    {
        Route::middleware(['web', 'auth'])
            ->post('admin/impersonation/leave', LeaveImpersonationController::class)
            ->name('identity.impersonation.leave');

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_START,
            function (): string {
                $impersonation = app(ImpersonationManager::class);

                if (! $impersonation->isImpersonating()) {
                    return '';
                }

                $user = Auth::user();
                $name = e($user instanceof User ? (string) $user->getAttribute('name') : 'user');
                $action = e(route('identity.impersonation.leave'));
                $csrf = (string) csrf_field();

                return '<div style="background:#b45309;color:#fff;padding:.5rem 1rem;display:flex;'
                    .'justify-content:space-between;align-items:center;gap:1rem;font-size:.875rem;">'
                    .'<span>You are impersonating <strong>'.$name.'</strong>. Actions you take are '
                    .'attributed to that account.</span>'
                    .'<form method="POST" action="'.$action.'" style="margin:0;">'.$csrf
                    .'<button type="submit" style="background:#fff;color:#b45309;border:0;'
                    .'border-radius:.25rem;padding:.25rem .75rem;font-weight:600;cursor:pointer;">'
                    .'Leave impersonation</button></form></div>';
            },
        );
    }

    private function protectSystemRoles(): void
    {
        Role::deleting(function (Role $role): void {
            if (in_array($role->name, ['super_admin', 'admin', 'instructor', 'student'], true)) {
                throw new ProtectedRoleException((string) $role->name);
            }
        });
    }

    private function registerRateLimiters(): void
    {
        // M9 — key on email + IP, matching the login limiter. IP alone let a distributed source
        // spray registrations/resets across many addresses; adding the credential means one email
        // can't be targeted from across the network and one IP can't spray many accounts.
        RateLimiter::for('identity-register', fn (Request $r) => Limit::perMinute(6)
            ->by(strtolower((string) $r->input('email')).'|'.$r->ip()));

        // Login keyed by email + IP: one attacker can't lock every account, and one account
        // can't be brute-forced from across the network.
        RateLimiter::for('identity-login', fn (Request $r) => Limit::perMinute(10)
            ->by(strtolower((string) $r->input('email')).'|'.$r->ip()));

        RateLimiter::for('identity-password', fn (Request $r) => Limit::perMinute(6)
            ->by(strtolower((string) $r->input('email')).'|'.$r->ip()));

        RateLimiter::for('identity-otp-verify', fn (Request $r) => Limit::perMinute(10)
            ->by(optional($r->user())->getAuthIdentifier() ?? $r->ip()));

        // Social redirect/callback are public and unauthenticated; key on IP so one source cannot
        // spray provider round-trips (and consume upstream IdP rate budget).
        RateLimiter::for('identity-social', fn (Request $r) => Limit::perMinute(20)->by((string) $r->ip()));

        // Public developer API — throttle PER KEY. Keyed on the acting personal-access-token id so
        // one developer key cannot exhaust the budget of another, falling back to IP for the
        // (test-only) transient-token path.
        RateLimiter::for('developer-api', function (Request $r): Limit {
            $user = $r->user();
            $token = $user instanceof User ? $user->currentAccessToken() : null;
            $key = $token instanceof PersonalAccessToken ? (string) $token->getKey() : (string) $r->ip();

            return Limit::perMinute(60)->by('developer-api|'.$key);
        });
    }

    private function registerListeners(): void
    {
        Event::listen(UserRegistered::class, SendEmailOtpOnRegistration::class);
        Event::listen(UserRegistered::class, SendPhoneOtpOnRegistration::class);
        Event::listen(UserLoggedIn::class, UpdateLastLoginTimestamp::class);
    }
}
