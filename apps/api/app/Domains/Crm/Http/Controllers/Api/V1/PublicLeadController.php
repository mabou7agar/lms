<?php

namespace App\Domains\Crm\Http\Controllers\Api\V1;

use App\Domains\Crm\Actions\Lead\SubmitPublicLeadAction;
use App\Domains\Crm\Http\Requests\PublicLeadRequest;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * PUBLIC (guest) enterprise-lead intake. No auth. Abuse is contained by the route throttle and the
 * honeypot in PublicLeadRequest; the response echoes NO personal data back (only the lead's public
 * id + a generic acknowledgement) so the endpoint cannot be used to reflect or enumerate PII.
 */
class PublicLeadController extends Controller
{
    public function store(PublicLeadRequest $request, SubmitPublicLeadAction $action): JsonResponse
    {
        $lead = $action->execute($request->validated(), $request->ip());

        return ApiResponse::created(
            ['id' => $lead->public_id, 'status' => $lead->status->value],
            'Thanks — our team will be in touch shortly.',
        );
    }
}
