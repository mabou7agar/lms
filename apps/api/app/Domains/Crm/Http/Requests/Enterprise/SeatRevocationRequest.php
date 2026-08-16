<?php

namespace App\Domains\Crm\Http\Requests\Enterprise;

use App\Platform\Shared\Requests\BaseFormRequest;

/**
 * Taking one employee's seat back. Always a single member: revoking in bulk would make it far too
 * easy to wipe a department's progress with one click.
 */
class SeatRevocationRequest extends BaseFormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'member_id' => ['required', 'uuid'],
        ];
    }
}
