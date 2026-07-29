<?php

namespace App\Platform\Media\Http\Requests;

use App\Platform\Shared\Requests\BaseFormRequest;

/**
 * P2/W04 - The client returns the single-use upload token to finalize. No media metadata is
 * accepted here — the provider is the sole source of truth (verifyUpload), so the client cannot
 * assert size/mime/duration.
 */
class FinalizeUploadRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'upload_token' => ['required', 'string', 'max:128'],
        ];
    }
}
