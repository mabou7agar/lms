<?php

namespace App\Contexts\Learning\Http\Resources;

use App\Contexts\Learning\Models\LessonVideoProgress;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * The server view of video progress after a heartbeat. `completed` is server-decided; the client's
 * job is to trust `position_seconds` for resume and `completed` for gating.
 */
class VideoProgressResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LessonVideoProgress $p */
        $p = $this->resource;

        return [
            'position_seconds' => $p->position_seconds,
            'watched_seconds' => $p->watched_seconds,
            'duration_seconds' => $p->duration_seconds,
            'completed' => $p->completed,
        ];
    }
}
