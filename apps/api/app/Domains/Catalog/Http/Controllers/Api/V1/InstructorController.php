<?php

namespace App\Domains\Catalog\Http\Controllers\Api\V1;

use App\Domains\Catalog\Http\Resources\InstructorProfileResource;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Public instructor directory (U4). Lists active instructors with a public profile, surfacing the
 * full profile plus its media as references. Reads through the Identity UserLookupPort — no direct
 * User/UserProfile model access. This is the richer sibling of the legacy `trainers` endpoint (which
 * stays a thin name/avatar/headline list for backward compatibility).
 */
class InstructorController extends Controller
{
    public function index(UserLookupPort $users): JsonResponse
    {
        return ApiResponse::success(InstructorProfileResource::collection($users->instructorProfiles()));
    }
}
