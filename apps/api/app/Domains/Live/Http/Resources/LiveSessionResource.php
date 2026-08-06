<?php

namespace App\Domains\Live\Http\Resources;

use App\Domains\Live\Models\LiveSession;
use App\Domains\Live\Services\TimezoneService;
use App\Platform\Identity\Contracts\Data\UserRef;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Session detail with times in both UTC and the session timezone. The raw meeting join_url is
 * NOT exposed — clients obtain it via the /join endpoint with a token.
 *
 * @property LiveSession $resource
 */
class LiveSessionResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        $tz = app(TimezoneService::class);

        return [
            'id' => $this->resource->public_id,
            'title' => $this->resource->title,
            'description' => $this->resource->description,
            'status' => $this->resource->status->value,
            'timezone' => $this->resource->timezone,
            'starts_at_utc' => $this->resource->starts_at?->toIso8601String(),
            'ends_at_utc' => $this->resource->ends_at?->toIso8601String(),
            'starts_at_local' => $this->resource->starts_at ? $tz->inZone($this->resource->starts_at, $this->resource->timezone) : null,
            'ends_at_local' => $this->resource->ends_at ? $tz->inZone($this->resource->ends_at, $this->resource->timezone) : null,
            'capacity' => $this->resource->capacity,
            'registered_count' => $this->resource->registeredCount(),
            'waiting_room' => $this->resource->waiting_room,
            'meeting_provider' => $this->resource->meeting_provider,
            'trainers' => $this->whenLoaded('trainerLinks', fn () => array_values(array_map(
                fn (UserRef $t) => ['id' => $t->publicId, 'name' => $t->name],
                app(UserLookupPort::class)->refsByIds($this->resource->trainerLinks->pluck('user_id')->all())
            ))),
            'recordings' => $this->whenLoaded('recordings', fn () => $this->resource->recordings->map(fn ($r) => [
                'id' => $r->public_id, 'status' => $r->status->value, 'url' => $r->url, 'duration_seconds' => $r->duration_seconds,
            ])->values()),
        ];
    }
}
