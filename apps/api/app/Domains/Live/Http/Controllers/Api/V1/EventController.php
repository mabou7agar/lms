<?php

namespace App\Domains\Live\Http\Controllers\Api\V1;

use App\Domains\Live\Enums\LiveSessionStatus;
use App\Domains\Live\Http\Resources\EventDetailResource;
use App\Domains\Live\Http\Resources\EventListResource;
use App\Domains\Live\Models\LiveSession;
use App\Platform\Identity\Contracts\Data\UserRef;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;

/**
 * Public, unauthenticated "Events" surface — a thin PRESENTATION layer over the existing Live
 * domain. It reuses App\Domains\Live models and exposes only marketing-safe fields via the
 * Event resources (never the meeting join_url / internals). No new Event domain model exists.
 */
class EventController extends Controller
{
    public function index(Request $request, UserLookupPort $users): JsonResponse
    {
        $filter = $request->string('filter')->toString() === 'past' ? 'past' : 'upcoming';
        $q = $request->string('q')->trim()->toString();

        $query = LiveSession::query()
            ->where('status', '!=', LiveSessionStatus::Cancelled->value)
            ->withCount(['registrations as registered_count' => fn (Builder $b) => $b->where('status', 'registered')])
            ->with('trainerLinks');

        if ($q !== '') {
            $query->where(function (Builder $b) use ($q): void {
                $b->where('title', 'ilike', '%'.$q.'%')
                    ->orWhere('description', 'ilike', '%'.$q.'%');
            });
        }

        if ($filter === 'past') {
            // Past = completed OR already ended (still excluding cancelled), newest first.
            $query->where(function (Builder $b): void {
                $b->where('status', LiveSessionStatus::Completed->value)
                    ->orWhere('ends_at', '<', now());
            })->orderByDesc('starts_at');
        } else {
            // Upcoming = scheduled/live and not yet ended, soonest first.
            $query->whereIn('status', [LiveSessionStatus::Scheduled->value, LiveSessionStatus::Live->value])
                ->where('ends_at', '>=', now())
                ->orderBy('starts_at');
        }

        $events = $query->paginate((int) $request->integer('per_page', 12))->withQueryString();

        $this->attachSpeakers($events->getCollection(), $users);

        return ApiResponse::paginated($events, EventListResource::class);
    }

    /**
     * Resolve every event's speaker names for the WHOLE page in one UserLookupPort call, then hand
     * each event its ordered names as an attribute the resource prefers — replacing the per-event
     * refsByIds() N+1. Output is identical: refsByIds preserves input order and skips unknown ids,
     * and this reproduces that order and skipping per event from the shared map.
     *
     * @param  Collection<int, LiveSession>  $events
     */
    private function attachSpeakers(Collection $events, UserLookupPort $users): void
    {
        $userIds = $events
            ->flatMap(fn (LiveSession $session): array => $session->trainerLinks->pluck('user_id')->all())
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $refs = $users->refsByIds($userIds);

        $events->each(function (LiveSession $session) use ($refs): void {
            $names = [];
            // pluck (not property access) in trainerLinks order — the exact order refsByIds
            // preserved — skipping ids with no user, so the output matches the previous per-event path.
            foreach ($session->trainerLinks->pluck('user_id') as $rawId) {
                $ref = $refs[(int) $rawId] ?? null;
                if ($ref instanceof UserRef) {
                    $names[] = ['name' => $ref->name];
                }
            }
            $session->setAttribute('speaker_names', $names);
        });
    }

    public function show(LiveSession $session): JsonResponse
    {
        $session->loadCount([
            'registrations as registered_count' => fn (Builder $b) => $b->where('status', 'registered'),
            'registrations as waitlist_count' => fn (Builder $b) => $b->where('status', 'waitlisted'),
        ]);
        $session->load(['trainerLinks', 'liveCourse.course']);

        return ApiResponse::success(new EventDetailResource($session));
    }
}
