<?php

namespace App\Contexts\Learning\Models;

use App\Contexts\Learning\Database\Factories\LessonProgressFactory;
use App\Contexts\Learning\Enums\LessonProgressStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonProgress extends Model
{
    /** @use HasFactory<LessonProgressFactory> */
    use HasFactory;

    protected $table = 'lesson_progress';

    protected $fillable = [
        'enrollment_id', 'lesson_id', 'status', 'position_seconds', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => LessonProgressStatus::class,
            'position_seconds' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function statusEnum(): LessonProgressStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof LessonProgressStatus ? $status : LessonProgressStatus::from((string) $status);
    }

    protected static function newFactory(): LessonProgressFactory
    {
        return LessonProgressFactory::new();
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === LessonProgressStatus::Completed;
    }
}
