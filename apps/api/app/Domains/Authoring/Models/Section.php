<?php

namespace App\Domains\Authoring\Models;

use App\Domains\Authoring\Database\Factories\SectionFactory;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Traits\HasPublicId;
use App\Platform\Shared\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A curriculum section belonging to a Catalog course. Holds ordered lessons.
 *
 * Only the column read across a context boundary is annotated here; the rest predate the
 * annotation convention and are covered by the PHPStan baseline.
 *
 * @property int $course_id
 * @property int $lock_version optimistic-locking counter (C3); advanced on every guarded write
 */
class Section extends Model
{
    /** @use HasFactory<SectionFactory> */
    use HasFactory;

    use HasPublicId;
    use HasTranslations;
    use SoftDeletes;

    protected $table = 'course_sections';

    protected $fillable = ['course_id', 'title', 'title_i18n', 'summary', 'summary_i18n', 'position', 'publish_state'];

    /** @var array<int, string> */
    protected array $translatable = ['title_i18n', 'summary_i18n'];

    protected function casts(): array
    {
        return [
            'publish_state' => PublishState::class,
            'position' => 'integer',
            'lock_version' => 'integer',
            'title_i18n' => 'array',
            'summary_i18n' => 'array',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('position');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('publish_state', PublishState::Published->value);
    }

    public function isPublished(): bool
    {
        return $this->publish_state === PublishState::Published;
    }

    protected static function newFactory(): SectionFactory
    {
        return SectionFactory::new();
    }
}
