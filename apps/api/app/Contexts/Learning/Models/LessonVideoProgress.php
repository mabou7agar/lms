<?php

namespace App\Contexts\Learning\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Server-authoritative video playback progress for one (enrollment, lesson) pair. Completion is
 * decided by the server (VideoProgressService) from watched duration vs a threshold; the client
 * never sets `completed`.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int $user_id
 * @property int $lesson_id
 * @property int $position_seconds
 * @property int $watched_seconds
 * @property int|null $duration_seconds
 * @property bool $completed
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $last_beat_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class LessonVideoProgress extends Model
{
    protected $table = 'lesson_video_progress';

    protected $fillable = [
        'enrollment_id', 'user_id', 'lesson_id',
        'position_seconds', 'watched_seconds', 'duration_seconds',
        'completed', 'completed_at', 'last_beat_at',
    ];

    protected function casts(): array
    {
        return [
            'position_seconds' => 'integer',
            'watched_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'completed' => 'boolean',
            'completed_at' => 'datetime',
            'last_beat_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
