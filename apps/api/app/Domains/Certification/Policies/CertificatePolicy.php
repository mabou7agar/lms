<?php

namespace App\Domains\Certification\Policies;

use App\Domains\Certification\Models\Certificate;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Policies\BasePolicy;

class CertificatePolicy extends BasePolicy
{
    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof Actor && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    // M10 — hasPermission(), not can(): under auth:sanctum, `$user->can()` resolves the request
    // guard rather than the `web` guard the permissions are seeded against, so it answers false even
    // for a genuine holder — leaving certificate revoke/reissue (and manager viewing) unreachable
    // for anyone but super_admin. Owner self-view via actorId() is unchanged.
    public function view(Actor $user, Certificate $certificate): bool
    {
        return $certificate->user_id === $user->actorId() || $user->hasPermission('certification.certificates.manage');
    }

    public function manage(Actor $user, Certificate $certificate): bool
    {
        return $user->hasPermission('certification.certificates.manage');
    }
}
