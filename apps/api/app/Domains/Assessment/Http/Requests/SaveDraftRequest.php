<?php

namespace App\Domains\Assessment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Learner draft content. Files are attached through their own endpoint, not here. */
class SaveDraftRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'text_response' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'external_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ];
    }
}
