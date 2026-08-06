# Support impersonation (Sprint 0.1.5)

Secure, session-scoped impersonation so authorised support/admins can reproduce a user's view.
This is a P2 add-on and is **not** part of the Sprint 0.1 DoD.

## Authorisation
- New permission `identity.users.impersonate` (`App\Platform\Identity\Enums\Permission::ImpersonateUsers`).
- Granted to the `support_agent` staff template; `super_admin` gets it through the central gate bypass.
- `UserPolicy::impersonate` gates the panel button (hidden for self and super_admin rows).

## Hard guards (enforced in `ImpersonationManager`, below the gate)
These run regardless of the super_admin gate bypass, so they cannot be routed around:
- no self-impersonation,
- a `super_admin` can never be impersonated (even by another `super_admin`),
- no nested impersonation (must leave first),
- the impersonate permission is re-checked in the manager (defence in depth).

## Flow
- Start: `ImpersonationManager::start($target)` stores the original actor id in the session, switches
  the web guard to the target, and writes `identity.user.impersonation.started` to the immutable
  `AuditLog`.
- Banner: a Filament `BODY_START` render hook shows an always-visible banner while impersonating,
  with a CSRF-protected "Leave impersonation" form.
- Leave: `POST /admin/impersonation/leave` (web + auth) → `LeaveImpersonationController` →
  `ImpersonationManager::leave()` restores the original actor, clears the session flag, and writes
  `identity.user.impersonation.ended`.

## Scope note
The banner and leave endpoint live in the `/admin` panel and are exercised for panel-capable
targets. Impersonation of end-users on a separate app frontend is out of scope for this ticket
(no such surface is wired here); the manager itself is surface-agnostic.

## Key files
- `app/Platform/Identity/Services/ImpersonationManager.php`
- `app/Platform/Identity/Exceptions/{CannotImpersonateException,NotImpersonatingException}.php`
- `app/Platform/Identity/Policies/UserPolicy.php` (`impersonate`)
- `app/Platform/Identity/Http/Controllers/LeaveImpersonationController.php`
- `app/Platform/Identity/Providers/IdentityServiceProvider.php` (`registerImpersonation`)
- `app/Platform/Identity/Filament/Resources/UserResource.php` (record action)
- Tests: `tests/Feature/Identity/ImpersonationTest.php`
