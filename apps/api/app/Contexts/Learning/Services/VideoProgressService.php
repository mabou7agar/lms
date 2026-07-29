<?php

namespace App\Contexts\Learning\Services;

use App\Contexts\Learning\Exceptions\InvalidProgressException;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LessonVideoProgress;
use App\Platform\Shared\Media\Contracts\MediaAssetPort;
use App\Platform\Shared\Services\BaseService;

/**
 * Records video playback progress. Server-authoritative and safe under high-frequency, repeated and
 * out-of-order heartbeats:
 *
 *   - The authoritative duration comes from the media asset (MediaAssetPort), NOT the client, so a
 *     client cannot inflate the denominator to force completion.
 *   - Impossible timestamps are rejected (negative, or past the known duration).
 *   - `watched_seconds` is MONOTONIC (max of old/new) so an out-of-order or rewound beat never
 *     regresses watched progress; `position_seconds` tracks the latest resume point.
 *   - Writes are throttled: a beat that arrives within the throttle window and does not advance the
 *     resume point is a no-op, so a 1s-interval client does not hammer the row.
 *   - Completion is decided HERE, by watched-vs-threshold — never a client-sent boolean.
 */
class VideoProgressService extends BaseService
{
    public function __construct(private readonly MediaAssetPort $mediaAssets) {}

    /**
     * @return array{progress: LessonVideoProgress, just_completed_video: bool}
     */
    public function record(Enrollment $enrollment, int $userId, int $lessonId, int $positionSeconds, ?int $clientDurationSeconds = null): array
    {
        if ($positionSeconds < 0) {
            throw new InvalidProgressException('Playback position cannot be negative.');
        }

        $existing = LessonVideoProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('lesson_id', $lessonId)
            ->first();

        $duration = $this->mediaAssets->assetForLesson($lessonId)->durationSeconds
            ?? $existing->duration_seconds
            ?? $clientDurationSeconds;

        if ($duration !== null && $duration > 0 && $positionSeconds > $duration) {
            throw new InvalidProgressException('Playback position exceeds the media duration.', [
                'position_seconds' => $positionSeconds,
                'duration_seconds' => $duration,
            ]);
        }

        $throttle = (int) config('learning.video.throttle_seconds', 5);

        // Throttle: within the window AND no forward progress => no-op write.
        if ($existing !== null
            && $existing->last_beat_at !== null
            && $existing->last_beat_at->diffInSeconds(now()) < $throttle
            && $positionSeconds <= $existing->position_seconds) {
            return ['progress' => $existing, 'just_completed_video' => false];
        }

        $progress = $existing ?? new LessonVideoProgress;

        $wasCompleted = (bool) ($progress->completed ?? false);
        $watched = max((int) ($progress->watched_seconds ?? 0), $positionSeconds);

        $completed = $wasCompleted;
        $completedAt = $progress->completed_at;
        if (! $completed && $duration !== null && $duration > 0) {
            $threshold = (float) config('learning.video.completion_threshold', 0.95);
            if ($watched >= (int) ceil($threshold * $duration)) {
                $completed = true;
                $completedAt = now();
            }
        }

        $progress->forceFill([
            'enrollment_id' => $enrollment->id,
            'user_id' => $userId,
            'lesson_id' => $lessonId,
            'position_seconds' => $positionSeconds,
            'watched_seconds' => $watched,
            'duration_seconds' => $duration,
            'completed' => $completed,
            'completed_at' => $completedAt,
            'last_beat_at' => now(),
        ])->save();

        return [
            'progress' => $progress,
            'just_completed_video' => $completed && ! $wasCompleted,
        ];
    }

    public function isCompletedFor(Enrollment $enrollment, int $lessonId): bool
    {
        return LessonVideoProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('lesson_id', $lessonId)
            ->where('completed', true)
            ->exists();
    }
}
