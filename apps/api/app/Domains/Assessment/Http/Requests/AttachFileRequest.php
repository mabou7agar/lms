<?php

namespace App\Domains\Assessment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Attaches an already-uploaded media asset (by its PUBLIC id) to a draft. The upload itself happens
 * against the Media platform; here we only reference it. Ownership is enforced by MediaReferencePort.
 */
class AttachFileRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'media_id' => ['required', 'uuid'],
        ];
    }
}
