<?php

namespace App\Platform\Media\Http\Requests;

use App\Platform\Shared\Requests\BaseFormRequest;

/**
 * P2/W04 - Removes a usage record for an asset by its scalar attachable reference.
 */
class DetachMediaRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'attachable_type' => ['required', 'string', 'max:191'],
            'attachable_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
