<?php

namespace App\Platform\Media\Http\Requests;

use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * P2/W04 - Adds a caption track (metadata only). BCP-47 is fully validated in MediaCaptionService;
 * this bounds shape/length only.
 */
class StoreCaptionRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'language' => ['required', 'string', 'max:35'],
            'label' => ['required', 'string', 'max:100'],
            'format' => ['sometimes', Rule::in(['vtt', 'srt'])],
            'storage_key' => ['nullable', 'string', 'max:1024'],
            'provider_ref' => ['nullable', 'string', 'max:255'],
        ];
    }
}
