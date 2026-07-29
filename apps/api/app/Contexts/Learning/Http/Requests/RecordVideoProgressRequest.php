<?php

namespace App\Contexts\Learning\Http\Requests;

use App\Platform\Shared\Requests\BaseFormRequest;

/**
 * Validates a video heartbeat. Note there is NO `completed` field: completion is decided by the
 * server from watched duration, never accepted from the client. `duration_seconds` is advisory —
 * the server prefers the authoritative media duration and only falls back to this hint.
 */
class RecordVideoProgressRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'position_seconds' => ['required', 'integer', 'min:0'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
