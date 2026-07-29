<?php

namespace App\Domains\Live\Http\Resources;

use App\Domains\Live\Models\LiveSession;
use App\Platform\Identity\Contracts\Data\UserRef;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Public, marketing-safe projection of a Live session presented as an "event" card.
 *
 * This is a PRESENTATION layer over the existing Live domain — it exposes ONLY fields that are
 * safe for anonymous visitors. The raw meeting join_url and any meeting internals are NEVER
 * serialized here (join_url is $hidden on the model and is not referenced).
 *
 * @property LiveSession $resource
 */
class EventListResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'title' => $this->resource->title,
            'description' => $this->resource->description,
            'status' => $this->resource->status->value,
            'timezone' => $this->resource->timezone,
            'starts_at' => $this->resource->starts_at?->toIso8601String(),
            'ends_at' => $this->resource->ends_at?->toIso8601String(),
            'capacity' => $this->resource->capacity,
            'registered_count' => $this->registeredCount(),
            'speakers' => $this->speakers(),
        ];
    }

    private function registeredCount(): int
    {
        // Prefer an eager-loaded withCount value to avoid N+1; fall back to the model helper.
        $count = $this->resource->getAttribute('registered_count');

        return $count !== null ? (int) $count : $this->resource->registeredCount();
    }

    /** @return array<int, array{name: string}> */
    private function speakers(): array
    {
        // Prefer the page-batched value set by EventController::index (one UserLookupPort call for
        // the whole list) — avoids the per-event refsByIds() N+1. Falls back to per-resource
        // resolution for any caller that did not pre-batch; output is identical either way.
        $prepared = $this->resource->getAttribute('speaker_names');
        if (is_array($prepared)) {
            /** @var array<int, array{name: string}> $prepared */
            return $prepared;
        }

        if (! $this->resource->relationLoaded('trainerLinks')) {
            return [];
        }

        $ids = $this->resource->trainerLinks->pluck('user_id')->map(fn ($v): int => (int) $v)->all();

        return array_values(array_map(
            fn (UserRef $t): array => ['name' => $t->name],
            app(UserLookupPort::class)->refsByIds($ids),
        ));
    }
}
