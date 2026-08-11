<?php

namespace App\Domains\Crm\Http\Requests;

use App\Domains\Crm\Enums\CrmTaskType;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', Rule::in(['lead', 'opportunity'])],
            'subject' => ['required', 'string'], // subject public_id
            'title' => ['required', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(CrmTaskType::values())],
            'priority' => ['nullable', 'string', 'max:16'],
            'due_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer'],
        ];
    }
}
