<?php

namespace App\Domains\Crm\Http\Requests\Enterprise;

use App\Domains\Crm\Enums\MemberRole;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class ChangeMemberRoleRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(MemberRole::values())],
        ];
    }
}
