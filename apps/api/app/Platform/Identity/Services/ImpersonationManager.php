<?php

namespace App\Platform\Identity\Services;

use App\Platform\Identity\Exceptions\CannotImpersonateException;
use App\Platform\Identity\Exceptions\NotImpersonatingException;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Audit\AuditLogger;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Secure, session-scoped user impersonation for support/administration. The original actor's id
 * is held in the session while the web guard is switched to the target; leaving restores it.
 * Both the start and the end are written to the immutable audit trail.
 *
 * The three hard guards — no self-impersonation, no impersonating a super_admin, no nested
 * impersonation — live here, BELOW the authorization gate, so the central super_admin gate bypass
 * can never route around them. The impersonate permission is also checked here (defence in depth)
 * and mirrored by UserPolicy::impersonate for the panel UI.
 */
class ImpersonationManager extends BaseService
{
    private const SESSION_KEY = 'identity.impersonator_id';

    public function __construct(private readonly AuditLogger $audit) {}

    public function start(User $target): void
    {
        $impersonator = $this->currentUser();

        if ($this->isImpersonating()) {
            throw CannotImpersonateException::alreadyActive();
        }

        if ($impersonator->is($target)) {
            throw CannotImpersonateException::self();
        }

        // Unconditional and below the gate: blocks even a super_admin actor from impersonating
        // another super_admin (the gate bypass would otherwise allow it).
        if ($target->hasRole('super_admin')) {
            throw CannotImpersonateException::superAdmin();
        }

        if (! $impersonator->can('identity.users.impersonate')) {
            throw CannotImpersonateException::unauthorized();
        }

        Session::put(self::SESSION_KEY, (int) $impersonator->getKey());
        Auth::login($target);

        $this->audit->log(
            'identity.user.impersonation.started',
            $target,
            ['impersonator_id' => (int) $impersonator->getKey(), 'target_id' => (int) $target->getKey()],
            (int) $impersonator->getKey(),
        );
    }

    public function leave(): void
    {
        $impersonatorId = $this->impersonatorId();

        if ($impersonatorId === null) {
            throw new NotImpersonatingException();
        }

        $impersonator = User::find($impersonatorId);

        if ($impersonator === null) {
            // The original actor is gone; end the session safely rather than restore a ghost.
            Session::forget(self::SESSION_KEY);
            Auth::logout();

            throw new NotImpersonatingException();
        }

        $current = Auth::user();
        $targetId = $current !== null ? (int) $current->getAuthIdentifier() : null;

        Auth::login($impersonator);
        Session::forget(self::SESSION_KEY);

        $this->audit->log(
            'identity.user.impersonation.ended',
            $impersonator,
            ['impersonator_id' => $impersonatorId, 'target_id' => $targetId],
            $impersonatorId,
        );
    }

    public function isImpersonating(): bool
    {
        return Session::has(self::SESSION_KEY);
    }

    public function impersonatorId(): ?int
    {
        $id = Session::get(self::SESSION_KEY);

        return $id !== null ? (int) $id : null;
    }

    private function currentUser(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw CannotImpersonateException::unauthorized();
        }

        return $user;
    }
}
