<?php

namespace App\Domains\Crm\Http\Requests\Enterprise;

use App\Domains\Crm\Services\OrganizationTargetResolver;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Handing seats from a PURCHASE to an org scope. Mirrors CourseAssignmentRequest so the manager
 * portal speaks one target vocabulary across both assignment surfaces.
 *
 * Deliberately NOT named SeatAssignmentRequest: that name belongs to the subscription seat pool's
 * assign/release endpoints, which take a single `member_id`. Reusing it here once broke both of
 * those with a 422.
 */
class EntitlementAssignmentRequest extends BaseFormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'target_type' => ['required', 'string', Rule::in(OrganizationTargetResolver::TYPES)],
            'target_id' => ['nullable', 'uuid', 'required_unless:target_type,organization'],
        ];
    }
}
