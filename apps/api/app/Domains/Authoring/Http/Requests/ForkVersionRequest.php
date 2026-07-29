<?php

namespace App\Domains\Authoring\Http\Requests;

use App\Platform\Shared\Requests\BaseFormRequest;

class ForkVersionRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Destination course PUBLIC id — resolved + authorized via CourseAccessPort.
            'destination_course_id' => ['required', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:200'],
        ];
    }
}
