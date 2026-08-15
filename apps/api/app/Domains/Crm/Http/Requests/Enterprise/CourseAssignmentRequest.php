<?php

namespace App\Domains\Crm\Http\Requests\Enterprise;

use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class CourseAssignmentRequest extends BaseFormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'course_id' => ['required', 'uuid'],
            'target_type' => ['required', 'string', Rule::in(['organization', 'member', 'department', 'team'])],
            'target_id' => ['nullable', 'uuid', 'required_unless:target_type,organization'],
        ];
    }
}
