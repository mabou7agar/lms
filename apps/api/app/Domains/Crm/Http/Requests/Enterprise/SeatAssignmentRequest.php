<?php

namespace App\Domains\Crm\Http\Requests\Enterprise;

use App\Platform\Shared\Requests\BaseFormRequest;

/**
 * Assigning or releasing a seat in the organization's SUBSCRIPTION seat pool. Purchased-training
 * seats are a different surface with a different shape — see EntitlementAssignmentRequest.
 */
class SeatAssignmentRequest extends BaseFormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // The member (by public id) to assign/release a seat for.
            'member_id' => ['required', 'uuid'],
        ];
    }
}
