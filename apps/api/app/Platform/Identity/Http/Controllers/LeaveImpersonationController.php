<?php

namespace App\Platform\Identity\Http\Controllers;

use App\Platform\Identity\Services\ImpersonationManager;
use Illuminate\Http\RedirectResponse;

/**
 * Ends the current impersonation session and returns to the admin panel. Reached from the
 * impersonation banner (POST, CSRF-protected, web + auth middleware). The restore, session
 * cleanup, and audit entry all live in ImpersonationManager::leave().
 */
class LeaveImpersonationController
{
    public function __invoke(ImpersonationManager $impersonation): RedirectResponse
    {
        $impersonation->leave();

        return redirect()->to('/admin');
    }
}
