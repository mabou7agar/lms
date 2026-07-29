<?php

namespace App\Platform\Media\Http\Requests;

use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * P2/W04 - Binds an asset to another context's entity by SCALAR polymorphic reference. attachable_id
 * is an internal id supplied by the owning context's UI; course_id (public id string) is resolved
 * through CourseAccessPort in the controller for the cross-course guard.
 */
class AttachMediaRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'attachable_type' => ['required', 'string', 'max:191'],
            'attachable_id' => ['required', 'integer', 'min:1'],
            'role' => ['sometimes', Rule::in(['primary', 'attachment', 'thumbnail'])],
            'course_id' => ['nullable', 'string', 'uuid'],
        ];
    }
}
