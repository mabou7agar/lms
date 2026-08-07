<?php

namespace App\Platform\Identity\Http\Controllers\Api\V1;

use App\Platform\Identity\Actions\Social\AuthenticateWithSocialIdentityAction;
use App\Platform\Identity\Http\Requests\SocialCallbackRequest;
use App\Platform\Identity\Http\Requests\SocialRedirectRequest;
use App\Platform\Identity\Http\Resources\UserResource;
use App\Platform\Identity\SocialAuth\SocialAuthManager;
use App\Platform\Identity\SocialAuth\SocialStateToken;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Public social-login endpoints (stateless, no session). `redirect` mints the provider consent URL
 * plus a signed CSRF state; `callback` verifies that state, exchanges the returned code for a
 * verified identity, and issues a Sanctum token — the same token shape as password login.
 *
 * All failure modes (SSO off, unknown/disabled provider, bad state, missing/conflicting email) are
 * raised as Identity exceptions that render the standard error envelope.
 */
class SocialAuthController extends Controller
{
    public function redirect(SocialRedirectRequest $request, string $provider, SocialAuthManager $manager, SocialStateToken $state): JsonResponse
    {
        $adapter = $manager->provider($provider);

        $redirectUri = (string) ($request->validated()['redirect_uri'] ?? config('sso.default_redirect_uri', ''));

        ['state' => $signedState, 'nonce' => $nonce] = $state->issue($provider, $redirectUri);

        return ApiResponse::success([
            'authorization_url' => $adapter->authorizationUrl($signedState, $nonce, $redirectUri),
            'state' => $signedState,
        ]);
    }

    public function callback(SocialCallbackRequest $request, string $provider, SocialAuthManager $manager, SocialStateToken $state, AuthenticateWithSocialIdentityAction $action): JsonResponse
    {
        $data = $request->validated();

        // Verify the signed state BEFORE any provider work — binds this callback to our redirect.
        $verified = $state->verify((string) $data['state'], $provider);

        $adapter = $manager->provider($provider);
        $identity = $adapter->exchange((string) $data['code'], $verified['nonce'], $verified['redirect_uri']);

        $result = $action->execute($identity, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return ApiResponse::success([
            'user' => new UserResource($result['user']->load('profile')),
            'token' => $result['token'],
        ], 'Logged in.');
    }
}
