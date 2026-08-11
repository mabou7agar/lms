<?php

namespace App\Domains\Crm\Http\Controllers\Api\V1;

use App\Domains\Crm\Actions\Opportunity\CreateOpportunityAction;
use App\Domains\Crm\Actions\Opportunity\MoveOpportunityStageAction;
use App\Domains\Crm\Http\Requests\MoveStageRequest;
use App\Domains\Crm\Http\Requests\StoreOpportunityRequest;
use App\Domains\Crm\Http\Resources\OpportunityResource;
use App\Domains\Crm\Models\Company;
use App\Domains\Crm\Models\Lead;
use App\Domains\Crm\Models\Opportunity;
use App\Domains\Crm\Models\Pipeline;
use App\Domains\Crm\Models\Stage;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class OpportunityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Opportunity::class);

        $perPage = max(1, min((int) $request->integer('per_page', 15), 100));
        $opportunities = Opportunity::query()
            ->with(['stage', 'pipeline'])
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        return ApiResponse::paginated($opportunities, OpportunityResource::class);
    }

    public function store(StoreOpportunityRequest $request, CreateOpportunityAction $action): JsonResponse
    {
        Gate::authorize('create', Opportunity::class);

        $data = $request->validated();

        $lead = ! empty($data['lead']) ? Lead::where('public_id', $data['lead'])->firstOrFail() : null;
        $company = ! empty($data['company']) ? Company::where('public_id', $data['company'])->firstOrFail() : null;
        $pipeline = ! empty($data['pipeline']) ? Pipeline::where('public_id', $data['pipeline'])->firstOrFail() : null;

        $opportunity = $action->execute($data, $lead, $pipeline, $company, $request->user()->id);

        return ApiResponse::created(new OpportunityResource($opportunity->load(['stage', 'pipeline'])), 'Opportunity created.');
    }

    public function moveStage(MoveStageRequest $request, Opportunity $opportunity, MoveOpportunityStageAction $action): JsonResponse
    {
        Gate::authorize('update', $opportunity);

        $stage = Stage::where('public_id', $request->validated()['stage'])->firstOrFail();

        $opportunity = $action->execute($opportunity, $stage);

        return ApiResponse::updated(new OpportunityResource($opportunity->load(['stage', 'pipeline'])), 'Opportunity stage updated.');
    }
}
