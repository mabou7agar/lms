<?php

namespace App\Domains\Live\Http\Resources;

use App\Domains\Live\Enums\RegistrationStatus;
use App\Domains\Live\Models\LiveSession;
use App\Domains\Live\Services\TimezoneService;
use App\Platform\Identity\Contracts\Data\UserRef;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Public, marketing-safe event detail projection over a Live session. Presentation only.
 *
 * Exposes ONLY anonymous-safe fields: title/description, status, times, capacity + registration
 * counts, speaker cards (name/headline/avatar resolved through the Identity UserLookupPort),
 * a derived agenda, the related Catalog course (title + public id), and the SEO block used by
 * the frontend to render JSON-LD (Event schema). The raw meeting join_url / meeting internals
 * are NEVER serialized (join_url is $hidden on the model and is not referenced here).
 *
 * @property LiveSession $resource
 */
class EventDetailResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $tz = app(TimezoneService::class);
        $startsAtUtc = $this->resource->starts_at?->toIso8601String();
        $endsAtUtc = $this->resource->ends_at?->toIso8601String();

        return [
            'id' => $this->resource->public_id,
            'title' => $this->resource->title,
            'description' => $this->resource->description,
            'status' => $this->resource->status->value,
            'timezone' => $this->resource->timezone,
            'starts_at' => $startsAtUtc,
            'ends_at' => $endsAtUtc,
            'starts_at_local' => $this->resource->starts_at
                ? $tz->inZone($this->resource->starts_at, $this->resource->timezone)
                : null,
            'ends_at_local' => $this->resource->ends_at
                ? $tz->inZone($this->resource->ends_at, $this->resource->timezone)
                : null,
            'capacity' => $this->resource->capacity,
            'registered_count' => $this->countFor('registered_count', RegistrationStatus::Registered->value),
            'waitlist_count' => $this->countFor('waitlist_count', RegistrationStatus::Waitlisted->value),
            'is_full' => $this->resource->isFull(),
            'agenda' => $this->agenda(),
            'speakers' => $this->speakers(),
            'related_course' => $this->relatedCourse(),
            // SEO block consumed by the frontend to build schema.org Event JSON-LD.
            'seo' => [
                'name' => $this->resource->title,
                'startDate' => $startsAtUtc,
                'endDate' => $endsAtUtc,
                'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
                'eventStatus' => $this->schemaEventStatus(),
                'location' => 'Online',
            ],
        ];
    }

    /**
     * Derive a lightweight agenda from what the domain actually models (no invented schema):
     * a single item using the session time window and its description as the summary.
     *
     * @return array<int, array{title: string, starts_at: ?string, ends_at: ?string, summary: ?string}>
     */
    private function agenda(): array
    {
        return [[
            'title' => $this->resource->title,
            'starts_at' => $this->resource->starts_at?->toIso8601String(),
            'ends_at' => $this->resource->ends_at?->toIso8601String(),
            'summary' => $this->resource->description,
        ]];
    }

    /** @return array<int, array{name: string, headline: ?string, avatar_path: ?string}> */
    private function speakers(): array
    {
        if (! $this->resource->relationLoaded('trainerLinks')) {
            return [];
        }

        $ids = $this->resource->trainerLinks->pluck('user_id')->map(fn ($v): int => (int) $v)->all();

        return array_values(array_map(
            fn (UserRef $t): array => [
                'name' => $t->name,
                'headline' => $t->headline,
                'avatar_path' => $t->avatarPath,
            ],
            app(UserLookupPort::class)->refsByIds($ids),
        ));
    }

    /**
     * Related Catalog course resolved through the Live→LiveCourse→Course relation. The Course
     * class is intentionally not imported/type-hinted here — only its public display attributes
     * are read — so this presentation resource adds no new cross-context static dependency.
     *
     * @return array{title: string, public_id: string}|null
     */
    private function relatedCourse(): ?array
    {
        $liveCourse = $this->resource->liveCourse;
        $course = $liveCourse?->course;

        if ($course === null) {
            return null;
        }

        return [
            'title' => (string) $course->title,
            'public_id' => (string) $course->public_id,
        ];
    }

    /** Prefer an eager-loaded withCount alias; otherwise count directly for this status. */
    private function countFor(string $attribute, string $status): int
    {
        $count = $this->resource->getAttribute($attribute);

        if ($count !== null) {
            return (int) $count;
        }

        return $this->resource->registrations()->where('status', $status)->count();
    }

    private function schemaEventStatus(): string
    {
        return match ($this->resource->status->value) {
            'cancelled' => 'https://schema.org/EventCancelled',
            default => 'https://schema.org/EventScheduled',
        };
    }
}
