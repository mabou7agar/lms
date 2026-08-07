<?php

namespace App\Domains\Authoring\Http\Resources;

use App\Domains\Authoring\Models\Lesson;
use App\Platform\Shared\Assessment\Contracts\LessonAssessmentPort;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property Lesson $resource
 */
class LessonResource extends BaseResource
{
    /**
     * @return array<string, mixed>|null
     *
     * @todo N+1: this resolves one assessment per quiz lesson when serialising a whole curriculum.
     *       Bounded in practice (only quiz lessons carry a reference), but a batch `describeMany`
     *       on the port would remove it if quiz-heavy courses become common.
     */
    private function assessmentRef(): ?array
    {
        $assessmentId = $this->resource->assessment_id;

        if ($assessmentId === null) {
            return null;
        }

        $ref = app(LessonAssessmentPort::class)->describe((int) $assessmentId);

        return $ref === null ? null : [
            'id' => $ref->publicId,
            'title' => $ref->title,
            'status' => $ref->status,
            'question_count' => $ref->questionCount,
            'version' => $ref->version,
        ];
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'title' => $this->resource->localized('title'),
            // C1: raw title locale map for EN/AR round-trip in the builder; `title` stays string-out.
            'title_i18n' => $this->resource->title_i18n ?? [],
            'type' => $this->resource->type->value,
            'content' => $this->resource->content,
            'position' => $this->resource->position,
            'publish_state' => $this->resource->publish_state->value,
            // C3: optimistic-lock counter the builder echoes back as expected_version on edits.
            'lock_version' => $this->resource->lock_version,
            'is_preview' => $this->resource->is_preview,
            'media' => new LessonMediaResource($this->whenLoaded('media')),
            // Resolved through the port, so Authoring never imports an Assessment class. Null both
            // when no assessment is attached and when a previously-attached one has been deleted —
            // a stale reference degrades to "no quiz" rather than breaking the curriculum tree.
            'assessment' => $this->assessmentRef(),
            'prerequisites' => $this->whenLoaded('prerequisites', fn () => $this->resource->prerequisites->map(fn ($p) => [
                'id' => $p->public_id, 'title' => $p->localized('title'),
            ])->values()),
        ];
    }
}
