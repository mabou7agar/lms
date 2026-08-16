<?php

namespace App\Domains\Crm\Http\Requests\Enterprise;

use App\Domains\Crm\Services\OrganizationTargetResolver;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Handing seats from a purchase to an org scope. Mirrors CourseAssignmentRequest so the manager
 * portal speaks one target vocabulary across both assignment surfaces.
 */
class SeatAssignmentRequest extends BaseFormRequest
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
