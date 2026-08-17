<?php

namespace App\Contexts\Analytics\Http\Controllers\Api\V1;

use App\Platform\Shared\Analytics\AnalyticsEventName;
use App\Platform\Shared\Analytics\Contracts\AnalyticsEventRecorder;
use App\Platform\Shared\Analytics\Data\AnalyticsEventInput;
use App\Platform\Shared\Catalog\Contracts\CourseLookupPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Where the browser reports what it alone can see: that a course page was looked at, that a CTA was
 * pressed, that somebody searched. State cannot answer "how many people viewed this course and did
 * not buy it", because looking leaves no row behind — this is the other half of that question.
 *
 * WHAT IT WILL ACCEPT is deliberately narrow. Only the client-reportable names pass; a browser that
 * could post `order_paid` could invent revenue, so the server-authoritative half of the vocabulary
 * is unreachable from here. The user is taken from the session when there is one and ignored when
 * the payload claims one, so a caller cannot attribute their activity to somebody else.
 *
 * Unauthenticated on purpose: the top of the funnel is anonymous, and requiring a session would
 * measure only the people who already signed up. Rate-limited instead, and every field is bounded
 * before it is stored.
 */
class EventCollectorController extends Controller
{
    public function store(Request $request, AnalyticsEventRecorder $recorder, CourseLookupPort $courses): JsonResponse
    {
        $validated = $request->validate([
            'events' => ['required', 'array', 'max:20'],
            'events.*.name' => ['required', 'string', Rule::in(AnalyticsEventName::clientReportable())],
            'events.*.course_id' => ['nullable', 'string', 'max:64'],
            'events.*.session_id' => ['nullable', 'string', 'max:64'],
            'events.*.utm_source' => ['nullable', 'string', 'max:64'],
            'events.*.utm_medium' => ['nullable', 'string', 'max:64'],
            'events.*.utm_campaign' => ['nullable', 'string', 'max:96'],
            // A search term is content, not identity — but it is still user-typed, so it is length
            // bounded and nothing else from the client is stored free-form.
            'events.*.term' => ['nullable', 'string', 'max:120'],
            'events.*.label' => ['nullable', 'string', 'max:64'],
        ]);

        $userId = $request->user()?->getAuthIdentifier();

        $inputs = [];

        foreach ($validated['events'] as $event) {
            // Public ids in, internal ids out. An unresolvable course simply means the dimension is
            // unknown; the event is still worth recording.
            $courseId = null;
            if (! empty($event['course_id'])) {
                $course = $courses->publishedCourseByPublicId((string) $event['course_id']);
                $courseId = $course === null ? null : (int) $course['id'];
            }

            $metadata = array_filter([
                'term' => $event['term'] ?? null,
                'label' => $event['label'] ?? null,
            ], static fn ($v): bool => $v !== null && $v !== '');

            $inputs[] = new AnalyticsEventInput(
                name: (string) $event['name'],
                userId: $userId === null ? null : (int) $userId,
                courseId: $courseId,
                sessionId: $event['session_id'] ?? null,
                utmSource: $event['utm_source'] ?? null,
                utmMedium: $event['utm_medium'] ?? null,
                utmCampaign: $event['utm_campaign'] ?? null,
                metadata: $metadata,
            );
        }

        $recorder->recordMany($inputs);

        // 202: the browser is telling us something, not asking for anything, and it must never wait
        // on or care about what became of it.
        return ApiResponse::success(null, 'Recorded.', 202);
    }
}
