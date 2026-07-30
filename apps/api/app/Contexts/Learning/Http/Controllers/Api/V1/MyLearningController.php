<?php

namespace App\Contexts\Learning\Http\Controllers\Api\V1;

use App\Contexts\Learning\Http\Resources\MyLearningItemResource;
use App\Contexts\Learning\Models\Enrollment;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MyLearningController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Paginated: a learner's enrollment list grows without bound over their lifetime, so it must
        // never be fetched in one unbounded query. The `data` envelope stays an array of the same
        // resource shape (meta/links are additive), so existing consumers are unaffected.
        $enrollments = Enrollment::query()
            ->where('user_id', $request->user()->id)
            ->with('course')
            ->latest('updated_at')
            ->paginate(max(1, min((int) $request->integer('per_page', 20), 100)))
            ->withQueryString();

        return ApiResponse::paginated($enrollments, MyLearningItemResource::class);
    }
}
