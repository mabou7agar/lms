<?php

namespace App\Domains\Crm\Http\Controllers\Api\V1;

use App\Domains\Crm\Actions\Lead\ConvertLeadAction;
use App\Domains\Crm\Actions\Lead\CreateLeadAction;
use App\Domains\Crm\Actions\Lead\MoveLeadStageAction;
use App\Domains\Crm\Http\Requests\CreateLeadRequest;
use App\Domains\Crm\Http\Requests\MoveStageRequest;
use App\Domains\Crm\Http\Resources\ContactResource;
use App\Domains\Crm\Http\Resources\LeadResource;
use App\Domains\Crm\Models\Lead;
use App\Domains\Crm\Models\Stage;
use App\Domains\Crm\Services\CrmSearchService;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class LeadController extends Controller
{
    public function index(Request $request, CrmSearchService $search): JsonResponse
    {
        Gate::authorize('viewAny', Lead::class);

        $leads = $search->leads($request->only(['q', 'status']), (int) $request->input('per_page', 15));

        return ApiResponse::paginated($leads, LeadResource::class);
    }

    public function store(CreateLeadRequest $request, CreateLeadAction $action): JsonResponse
    {
        Gate::authorize('create', Lead::class);

        $lead = $action->execute($request->validated(), $request->user()->id);

        return ApiResponse::created(new LeadResource($lead->load('stage')), 'Lead created.');
    }

    /** Move a lead to another stage of its pipeline. */
    public function moveStage(MoveStageRequest $request, Lead $lead, MoveLeadStageAction $action): JsonResponse
    {
        Gate::authorize('update', $lead);

        $stage = Stage::where('public_id', $request->validated()['stage'])->firstOrFail();

        $lead = $action->execute($lead, $stage);

        return ApiResponse::updated(new LeadResource($lead->load('stage')), 'Lead stage updated.');
    }

    /** Convert a qualified lead into a contact (guarded against double-conversion). */
    public function convert(Lead $lead, ConvertLeadAction $action): JsonResponse
    {
        Gate::authorize('convert', $lead);

        $contact = $action->execute($lead);

        return ApiResponse::created(new ContactResource($contact), 'Lead converted.');
    }
}
