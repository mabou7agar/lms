<?php

namespace App\Platform\Media\Http\Requests;

use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaType;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * P2/W04 - Validates a direct-upload request. Type/size are further bounded against the purpose in
 * MediaUploadService; these rules are the coarse gate. course_id is a course PUBLIC id (string),
 * resolved through CourseAccessPort in the controller (never a raw internal id from the client).
 */
class CreateDirectUploadRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', new Enum(MediaType::class)],
            'purpose' => ['required', new Enum(MediaPurpose::class)],
            'filename' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', 'max:191'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'course_id' => ['nullable', 'string', 'uuid'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'role' => ['sometimes', Rule::in(['primary', 'attachment', 'thumbnail'])],
        ];
    }
}
