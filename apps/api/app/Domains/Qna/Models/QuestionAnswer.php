<?php

declare(strict_types=1);

namespace App\Domains\Qna\Models;

use App\Domains\Qna\Database\Factories\QuestionAnswerFactory;
use App\Platform\Shared\Moderation\Concerns\CanBeReported;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An answer to a course question. No tenant scope of its own — it inherits the question's tenancy
 * transitively (it can only ever be reached through a question the actor may already see). `body` is
 * sanitized on the write path (the actions). `is_instructor` is frozen at create time and reflects
 * whether the author could manage the course's content when they answered.
 *
 * @property int $id
 * @property string $public_id
 * @property int $question_id
 * @property int $user_id
 * @property string $body sanitized HTML
 * @property bool $is_instructor
 * @property bool $accepted
 * @property bool $is_official
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class QuestionAnswer extends Model
{
    use CanBeReported;

    /** @use HasFactory<QuestionAnswerFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    protected $table = 'question_answers';

    /**
     * is_instructor and accepted are derived state, written only by the actions via direct attribute
     * assignment — never mass-assigned from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'question_id', 'user_id', 'body',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_instructor' => 'boolean',
            'accepted' => 'boolean',
            'is_official' => 'boolean',
        ];
    }

    /** @return BelongsTo<CourseQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(CourseQuestion::class, 'question_id');
    }

    protected static function newFactory(): QuestionAnswerFactory
    {
        return QuestionAnswerFactory::new();
    }
}
