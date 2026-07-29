<?php

namespace App\Domains\Assessment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Optional note explaining what the learner must revise. */
class RequestChangesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ];
    }
}
