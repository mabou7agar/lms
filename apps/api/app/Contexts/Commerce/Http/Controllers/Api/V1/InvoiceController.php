<?php

namespace App\Contexts\Commerce\Http\Controllers\Api\V1;

use App\Contexts\Commerce\Actions\Invoice\GetInvoiceForUserAction;
use App\Contexts\Commerce\Actions\Invoice\ListInvoicesForUserAction;
use App\Contexts\Commerce\Http\Resources\InvoiceResource;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Learner billing portal read endpoints. Thin: resolve the authenticated user, delegate to a
 * read-side Action, and shape the result through InvoiceResource. Ownership is enforced inside
 * the Actions (invoice -> order -> user_id), so a user only ever sees their own invoices. No
 * persistence, no business logic here.
 */
class InvoiceController extends Controller
{
    public function index(Request $request, ListInvoicesForUserAction $action): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        $invoices = $action->execute($this->userId($request), $perPage);

        return ApiResponse::success(InvoiceResource::collection($invoices));
    }

    public function show(Request $request, string $invoice, GetInvoiceForUserAction $action): JsonResponse
    {
        $found = $action->execute($this->userId($request), $invoice);

        return ApiResponse::success(new InvoiceResource($found));
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }
}
