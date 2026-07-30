<?php

namespace App\Domains\Certification\Http\Controllers\Api\V1;

use App\Domains\Certification\Http\Resources\CertificateListItemResource;
use App\Domains\Certification\Models\Certificate;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MyCertificatesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Paginated: a learner's certificate list grows without bound. The `data` envelope stays an
        // array of the same resource shape (meta/links additive), so existing consumers are unaffected.
        $certificates = Certificate::query()
            ->where('user_id', $request->user()->id)
            ->with('course')
            ->latest('id')
            ->paginate(max(1, min((int) $request->integer('per_page', 20), 100)))
            ->withQueryString();

        return ApiResponse::paginated($certificates, CertificateListItemResource::class);
    }
}
