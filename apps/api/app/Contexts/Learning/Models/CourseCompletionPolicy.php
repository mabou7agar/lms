<?php

namespace App\Contexts\Learning\Models;

use App\Contexts\Learning\Support\CompletionPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Stored, per-course completion policy. Keyed BY course_id (its primary key, non-incrementing): one
 * row per course, absent by default. Absence is meaningful — it means "use the platform default"
 * ({@see CompletionPolicy::default()}), which is byte-for-byte the behaviour that predated this table.
 *
 * The model is a thin persistence shell: rule composition happens on the {@see CompletionPolicy}
 * value object returned by {@see toValueObject()}, never against this Eloquent row directly.
 *
 * @property int $course_id
 * @property bool $require_all_lessons
 * @property int|null $min_watch_percentage 0-100; null = off
 * @property bool $require_required_quizzes
 * @property bool $require_final_exam
 * @property int|null $final_exam_assessment_id
 * @property bool $require_required_assignments
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CourseCompletionPolicy extends Model
{
    protected $table = 'course_completion_policies';

    protected $primaryKey = 'course_id';

    public $incrementing = false;

    protected $keyType = 'int';

    /** @var list<string> */
    protected $fillable = [
        'course_id',
        'require_all_lessons',
        'min_watch_percentage',
        'require_required_quizzes',
        'require_final_exam',
        'final_exam_assessment_id',
        'require_required_assignments',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'require_all_lessons' => 'boolean',
            'min_watch_percentage' => 'integer',
            'require_required_quizzes' => 'boolean',
            'require_final_exam' => 'boolean',
            'final_exam_assessment_id' => 'integer',
            'require_required_assignments' => 'boolean',
        ];
    }

    /** The value-object accessor: rules are evaluated against this, not the row. */
    public function toValueObject(): CompletionPolicy
    {
        return CompletionPolicy::fromModel($this);
    }
}
