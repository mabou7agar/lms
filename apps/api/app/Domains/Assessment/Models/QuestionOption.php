<?php

namespace App\Domains\Assessment\Models;

use App\Domains\Assessment\Database\Factories\QuestionOptionFactory;
use App\Platform\Shared\Traits\HasPublicId;
use App\Platform\Shared\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A choice OR an accepted answer, depending on the parent question's type. See the table migration
 * for the per-type meaning of `label`, `value` and `group_index`.
 *
 * @property int $id
 * @property string $public_id
 * @property int $question_id
 * @property string|null $label learner-visible choice text; null on pure accepted-answer rows
 * @property string|null $value machine-comparable: accepted answer, numeric target, matching key
 * @property bool $is_correct
 * @property int $group_index sub-part index (which blank / which pair); 0 for single-part questions
 * @property string|null $feedback
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AssessmentQuestion|null $question
 */
class QuestionOption extends Model
{
    /** @use HasFactory<QuestionOptionFactory> */
    use HasFactory;

    use HasPublicId;
    use HasTranslations;

    protected $table = 'assessment_question_options';

    /** @var list<string> */
    protected $fillable = [
        'question_id', 'label', 'label_i18n', 'value', 'is_correct', 'group_index', 'feedback', 'feedback_i18n', 'position',
    ];

    /** @var array<int, string> */
    protected array $translatable = ['label_i18n', 'feedback_i18n'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'group_index' => 'integer',
            'position' => 'integer',
            'label_i18n' => 'array',
            'feedback_i18n' => 'array',
        ];
    }

    /** @return BelongsTo<AssessmentQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(AssessmentQuestion::class, 'question_id');
    }

    /** What a text answer is compared against: the explicit `value`, else the visible label. */
    public function comparableValue(): string
    {
        return (string) ($this->value ?? $this->label ?? '');
    }

    protected static function newFactory(): QuestionOptionFactory
    {
        return QuestionOptionFactory::new();
    }
}
