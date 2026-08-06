<?php

namespace App\Platform\Identity\Providers;

use App\Platform\Identity\Adapters\CurrentUserAdapter;
use App\Platform\Identity\Adapters\UserLookupAdapter;
use App\Platform\Identity\Adapters\UserPermissionAdapter;
use App\Platform\Identity\Adapters\UserRoleAdapter;
use App\Platform\Identity\Contracts\CurrentUserPort;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Identity\Contracts\UserPermissionPort;
use App\Platform\Identity\Contracts\UserRolePort;
use App\Platform\Identity\Events\UserLoggedIn;
use App\Platform\Identity\Events\UserRegistered;
use App\Platform\Identity\Exceptions\ProtectedRoleException;
use App\Platform\Identity\Listeners\SendEmailOtpOnRegistration;
use App\Platform\Identity\Listeners\SendPhoneOtpOnRegistration;
use App\Platform\Identity\Listeners\UpdateLastLoginTimestamp;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Models\UserDevice;
use App\Platform\Identity\Policies\DevicePolicy;
use App\Platform\Identity\Policies\RolePolicy;
use App\Platform\Identity\Policies\UserPolicy;
use App\Platform\Identity\Tenancy\RoleBasedTenancyBypassPolicy;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;
use App\Platform\Shared\Tenancy\TenancyBypassPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;

/**
 * Wires the Identity module: config, migrations, split route files, policies, named rate
 * limiters, and event→listener bindings. Registered in bootstrap/providers.php.
 */
class IdentityServiceProvider extends BaseDomainServiceProvider
{
    protected array $routeFiles = ['routes/auth.php', 'routes/profile.php', 'routes/devices.php'];

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

        // Identity owns RBAC, so it provides the concrete tenancy-bypass policy (platform admins
        // bypass tenant scoping). This overrides the Shared NullTenancyBypassPolicy default.
        $this->app->bind(TenancyBypassPolicy::class, RoleBasedTenancyBypassPolicy::class);

        // IdentityContracts ports → Identity adapters. Identity is the ONLY layer that binds these
        // (it alone may touch the User model). Consumers depend on the interfaces, not the adapters.
        $this->app->bind(CurrentUserPort::class, CurrentUserAdapter::class);
        $this->app->bind(UserLookupPort::class, UserLookupAdapter::class);
        $this->app->bind(UserPermissionPort::class, UserPermissionAdapter::class);
        $this->app->bind(UserRolePort::class, UserRoleAdapter::class);
    }

    protected function bootDomain(): void
    {
        $this->registerRateLimiters();
        $this->registerListeners();
        $this->protectSystemRoles();
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
    }

    private function registerListeners(): void
    {
        Event::listen(UserRegistered::class, SendEmailOtpOnRegistration::class);
        Event::listen(UserRegistered::class, SendPhoneOtpOnRegistration::class);
        Event::listen(UserLoggedIn::class, UpdateLastLoginTimestamp::class);
    }
}
