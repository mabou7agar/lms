<?php

namespace App\Contexts\Commerce\Http\Controllers\Api\V1;

use App\Contexts\Commerce\Actions\CreditNote\ListCreditNotesAction;
use App\Contexts\Commerce\Http\Resources\CreditNoteResource;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Admin credit notes read endpoint. Thin: clamp pagination, delegate to a read-side Action, and
 * shape the result through CreditNoteResource. Authorization is enforced by the route's
 * commerce.orders.view permission middleware. No persistence, no business logic here.
 */
class CreditNoteController extends Controller
{
    public function index(Request $request, ListCreditNotesAction $action): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        $creditNotes = $action->execute($perPage);

        return ApiResponse::paginated($creditNotes, CreditNoteResource::class);
    }
}
