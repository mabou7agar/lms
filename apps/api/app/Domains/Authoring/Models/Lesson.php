<?php

namespace App\Domains\Authoring\Models;

use App\Domains\Authoring\Database\Factories\LessonFactory;
use App\Domains\Authoring\Enums\LessonType;
use App\Domains\Authoring\Enums\PublishState;
use App\Platform\Shared\Traits\HasPublicId;
use App\Platform\Shared\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A lesson within a section. Carries type + content metadata and (for media types) a single
 * LessonMedia metadata row. No playback, progress, or enrollment logic lives here.
 *
 * Only the columns added after this model was written are annotated. The rest predate the
 * annotation convention and are covered by the PHPStan baseline; a full pass belongs in its own
 * change rather than smuggled in with a feature.
 *
 * `$type` is annotated because the quiz wiring now branches on it in a second place, and an
 * undeclared enum-cast property silently degrades to mixed — exactly the kind of blind spot that
 * hid the multi-blank grading defect. The two baseline entries it covered are removed with it.
 *
 * The properties read by publish-readiness evaluation are annotated for the same reason `$type`
 * was: an undeclared cast property degrades to mixed, and readiness decides whether a course may
 * ship. The remaining columns stay baselined pending a dedicated annotation pass.
 *
 * @property LessonType $type
 * @property PublishState $publish_state
 * @property string $public_id
 * @property string $title
 * @property array<string, mixed>|null $content
 * @property int|null $assessment_id reference to an Assessment; resolved via LessonAssessmentPort
 * @property int $lock_version optimistic-locking counter (C3); advanced on every guarded write
 * @property int $section_id
 */
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory;

    use HasPublicId;
    use HasTranslations;
    use SoftDeletes;

    /**
     * `assessment_id` is a REFERENCE, not ownership: the Assessment lives in its own context and
     * may be reused. Authoring never imports an Assessment class — it resolves the reference
     * through LessonAssessmentPort.
     */
    protected $fillable = ['section_id', 'title', 'title_i18n', 'type', 'content', 'assessment_id', 'position', 'publish_state', 'is_preview'];

    /** @var array<int, string> */
    protected array $translatable = ['title_i18n'];

    protected function casts(): array
    {
        return [
            'type' => LessonType::class,
            'publish_state' => PublishState::class,
            'content' => 'array',
            'title_i18n' => 'array',
            'position' => 'integer',
            'lock_version' => 'integer',
            'is_preview' => 'boolean',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function media(): HasOne
    {
        return $this->hasOne(LessonMedia::class);
    }

    /**
     * First-class content blocks belonging to this lesson.
     *
     * @return HasMany<Block, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class)->orderBy('position');
    }

    /** Lessons that must be completed before this one. */
    public function prerequisites(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'lesson_prerequisites', 'lesson_id', 'prerequisite_lesson_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('publish_state', PublishState::Published->value);
    }

    public function isPublished(): bool
    {
        return $this->publish_state === PublishState::Published;
    }

    protected static function newFactory(): LessonFactory
    {
        return LessonFactory::new();
    }
}
