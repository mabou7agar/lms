<?php

namespace App\Platform\Identity\Http\Middleware;

use App\Platform\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps last_used_at on the acting developer API key for every request that reaches a scoped
 * endpoint. Sanctum's guard already refreshes last_used_at at auth time; this makes the
 * public-API contract explicit and independent of that internal behaviour. TransientToken (used by
 * Sanctum::actingAs in tests) is skipped because it has no persistent row.
 */
class TrackTokenUsage
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if ($token instanceof PersonalAccessToken) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
