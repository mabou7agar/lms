<?php

namespace App\Contexts\Learning\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records that a learner completed one content block of a lesson. Idempotent per
 * (enrollment, block_ref). Feeds the "required blocks" half of the lesson completion rule.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int $user_id
 * @property int $lesson_id
 * @property string $block_ref
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class LearnerBlockProgress extends Model
{
    protected $table = 'learner_block_progress';

    protected $fillable = [
        'enrollment_id', 'user_id', 'lesson_id', 'block_ref', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
