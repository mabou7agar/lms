<?php

namespace App\Platform\Identity\Http\Requests;

use App\Platform\Identity\Enums\SsoDomainMode;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a mode change on an existing SSO domain mapping. The domain itself is immutable once
 * claimed (delete + re-add to move it), so only the mode is editable here.
 */
class UpdateSsoDomainRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in(SsoDomainMode::values())],
        ];
    }
}
