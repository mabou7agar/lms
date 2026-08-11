<?php

namespace App\Platform\Identity\Http\Controllers\Api\V1;

use App\Platform\Identity\Actions\ApiKey\CreateApiKeyAction;
use App\Platform\Identity\Actions\ApiKey\RevokeApiKeyAction;
use App\Platform\Identity\Enums\ApiScope;
use App\Platform\Identity\Enums\Permission;
use App\Platform\Identity\Http\Requests\CreateApiKeyRequest;
use App\Platform\Identity\Http\Resources\ApiKeyResource;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * First-party, org-admin management of developer API keys (create / list / revoke). Guarded by
 * auth:sanctum + the `keys:manage` token ability (so a scoped developer key can never manage keys)
 * and by the Identity ManageUsers permission (the org-admin gate). Everything is tenant-scoped to
 * the acting admin's organization.
 */
class DeveloperApiKeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $admin = $this->admin($request);

        $error = $this->guard($admin);
        if ($error !== null) {
            return $error;
        }

        $keys = PersonalAccessToken::query()
            ->where('organization_id', $admin->getAttribute('organization_id'))
            ->latest('id')
            ->get();

        return ApiResponse::success(ApiKeyResource::collection($keys));
    }

    public function store(CreateApiKeyRequest $request, CreateApiKeyAction $action): JsonResponse
    {
        $admin = $this->admin($request);

        $error = $this->guard($admin);
        if ($error !== null) {
            return $error;
        }

        /** @var list<string> $scopes */
        $scopes = array_values(array_unique((array) $request->validated('scopes')));

        // "≤ creator permissions": every granted scope's required permission must be held by the
        // creator. Scope-catalog membership was already enforced by the FormRequest.
        foreach ($scopes as $scope) {
            $required = ApiScope::from($scope)->requiredPermission();

            if ($required !== null && ! $admin->hasPermission($required)) {
                return ApiResponse::error(
                    'SCOPE_FORBIDDEN',
                    'You cannot grant a scope that exceeds your own permissions.',
                    ['scope' => $scope, 'requires_permission' => $required],
                    403,
                );
            }
        }

        $expiresRaw = $request->validated('expires_at');
        $expiresAt = is_string($expiresRaw) && $expiresRaw !== '' ? Carbon::parse($expiresRaw) : null;

        $newToken = $action->execute($admin, (string) $request->validated('name'), $scopes, $expiresAt);

        // Plaintext token returned EXACTLY ONCE. It is never stored and never returned again.
        return ApiResponse::created([
            'id' => $newToken->accessToken->getKey(),
            'name' => $newToken->accessToken->name,
            'scopes' => $scopes,
            'expires_at' => $newToken->accessToken->expires_at?->toIso8601String(),
            'token' => $newToken->plainTextToken,
        ], 'API key created. Copy the token now; it will not be shown again.');
    }

    public function destroy(Request $request, string $token, RevokeApiKeyAction $action): JsonResponse
    {
        $admin = $this->admin($request);

        $error = $this->guard($admin);
        if ($error !== null) {
            return $error;
        }

        $revoked = $action->execute(
            (int) $admin->getAttribute('organization_id'),
            (int) $token,
        );

        if (! $revoked) {
            return ApiResponse::error('NOT_FOUND', 'API key not found.', [], 404);
        }

        return ApiResponse::deleted('API key revoked.');
    }

    /**
     * Enforce the org-admin gate and organization membership. Returns an error response to short
     * out on, or null when the caller may proceed.
     */
    private function guard(User $admin): ?JsonResponse
    {
        if (! $admin->hasPermission(Permission::ManageUsers->value)) {
            return ApiResponse::error('FORBIDDEN', 'You are not allowed to manage API keys.', [], 403);
        }

        if ($admin->getAttribute('organization_id') === null) {
            return ApiResponse::error(
                'NO_ORGANIZATION',
                'API keys can only be managed by an account that belongs to an organization.',
                [],
                422,
            );
        }

        return null;
    }

    private function admin(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
