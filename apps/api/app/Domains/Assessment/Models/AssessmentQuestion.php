<?php

namespace App\Domains\Assessment\Models;

use App\Domains\Assessment\Database\Factories\AssessmentQuestionFactory;
use App\Domains\Assessment\Enums\Difficulty;
use App\Domains\Assessment\Enums\QuestionType;
use App\Platform\Shared\Traits\HasPublicId;
use App\Platform\Shared\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A single question. `type` is the discriminator; `config` holds everything type-specific so new
 * types never require a migration. This model contains no grading logic — see Grading\GraderRegistry.
 *
 * `points` and `negative_points` are annotated as string because Laravel's `decimal:` cast returns
 * a formatted string on read, not a float. Callers cast explicitly at the point of arithmetic.
 *
 * @property int $id
 * @property string $public_id
 * @property int $assessment_id
 * @property QuestionType $type
 * @property string $prompt sanitized HTML
 * @property array<string, mixed>|null $config type-specific settings; keys vary by QuestionType
 *                                             (e.g. partial_credit, case_sensitive, normalize_arabic,
 *                                             and later tolerance / min_words / language)
 * @property string|null $explanation sanitized HTML, revealed per FeedbackMode
 * @property string|null $hint sanitized HTML, visible during the attempt
 * @property string $points decimal:2
 * @property string $negative_points decimal:2, positive magnitude; the scorer applies the sign
 * @property Difficulty|null $difficulty
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Assessment|null $assessment
 * @property-read Collection<int, QuestionOption> $options
 * @property-read Collection<int, QuestionOption> $correctOptions
 * @property-read Collection<int, AssessmentTag> $tags
 * @property-read int|null $options_count
 * @property-read int|null $correct_options_count  present only via withCount('correctOptions')
 */
class AssessmentQuestion extends Model
{
    /** @use HasFactory<AssessmentQuestionFactory> */
    use HasFactory;

    use HasPublicId;
    use HasTranslations;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'assessment_id', 'type', 'prompt', 'prompt_i18n', 'config', 'explanation', 'explanation_i18n', 'hint', 'hint_i18n',
        'points', 'negative_points', 'difficulty', 'position',
    ];

    /** @var array<int, string> */
    protected array $translatable = ['prompt_i18n', 'explanation_i18n', 'hint_i18n'];

    /**
     * Rich-HTML translatable maps sanitized per locale on write (via HasTranslations). Mirrors the
     * fields SaveQuestionAction runs through the HtmlSanitizer for the legacy scalars.
     *
     * @var array<int, string>
     */
    protected array $translatableHtml = ['prompt_i18n', 'explanation_i18n', 'hint_i18n'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'difficulty' => Difficulty::class,
            'config' => 'array',
            'points' => 'decimal:2',
            'negative_points' => 'decimal:2',
            'position' => 'integer',
            'prompt_i18n' => 'array',
            'explanation_i18n' => 'array',
            'hint_i18n' => 'array',
        ];
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /** @return HasMany<QuestionOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class, 'question_id')->orderBy('position');
    }

    /**
     * The answer key: options flagged correct. Never serialised to an unentitled learner.
     *
     * @return HasMany<QuestionOption, $this>
     */
    public function correctOptions(): HasMany
    {
        return $this->options()->where('is_correct', true);
    }

    /** @return MorphToMany<AssessmentTag, $this> */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(AssessmentTag::class, 'taggable', 'assessment_taggables', 'taggable_id', 'tag_id');
    }

    /** Typed read of a `config` key with a default — keeps callers free of null juggling. */
    public function setting(string $key, mixed $default = null): mixed
    {
        $config = $this->config;

        return is_array($config) ? ($config[$key] ?? $default) : $default;
    }

    protected static function newFactory(): AssessmentQuestionFactory
    {
        return AssessmentQuestionFactory::new();
    }
}
