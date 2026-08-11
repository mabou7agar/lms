<?php

namespace App\Domains\Crm\Http\Requests;

use App\Platform\Shared\Requests\BaseFormRequest;

/**
 * Shared stage-move payload for leads and opportunities: the target stage's public_id.
 */
class MoveStageRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'stage' => ['required', 'string'], // Stage public_id
        ];
    }
}
