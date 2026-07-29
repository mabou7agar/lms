<?php

namespace App\Domains\Authoring\Models;

use App\Domains\Authoring\Database\Factories\ModuleFactory;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Exceptions\CrossCourseReferenceException;
use App\Domains\Authoring\Exceptions\InvalidCurriculumException;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P2/W02 - Optional nested grouping for a Catalog course (module -> sub-module -> ...). Coexists
 * with the legacy Section; does not replace it. Additive; feature gated by `authoring.blocks_enabled`.
 *
 * @property int $course_id
 * @property int|null $parent_id
 * @property PublishState $publish_state
 * @property string $public_id
 * @property int $position
 */
class Module extends Model
{
    /** @use HasFactory<ModuleFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    protected $table = 'authoring_modules';

    protected $fillable = ['course_id', 'parent_id', 'title', 'summary', 'position', 'publish_state'];

    protected function casts(): array
    {
        return [
            'publish_state' => PublishState::class,
            'position' => 'integer',
        ];
    }

    /**
     * Keep the module tree tenant-safe by construction: a module may only nest under a parent in the
     * SAME course, may not be its own parent, and may not form an ancestor cycle. This makes the
     * self-nesting structure safe before any write path is wired.
     */
    protected static function booted(): void
    {
        static::saving(function (Module $module): void {
            $parentId = $module->parent_id;
            if ($parentId === null) {
                return;
            }

            if ((int) $parentId === (int) $module->getKey()) {
                throw new InvalidCurriculumException('A module cannot be its own parent.');
            }

            $parent = static::query()->find($parentId);
            if ($parent === null) {
                return; // the FK guarantees existence at the DB layer
            }

            if ((int) $parent->course_id !== (int) $module->course_id) {
                throw new CrossCourseReferenceException('A module must nest within the same course as its parent.');
            }

            // Walk ancestors to reject cycles (A -> B -> A) and runaway depth.
            $ancestor = $parent;
            $depth = 0;
            while ($ancestor !== null) {
                if ((int) $ancestor->getKey() === (int) $module->getKey()) {
                    throw new InvalidCurriculumException('Module nesting would create a cycle.');
                }
                if (++$depth > 64) {
                    throw new InvalidCurriculumException('Module nesting is too deep.');
                }
                $ancestor = $ancestor->parent_id ? static::query()->find($ancestor->parent_id) : null;
            }
        });
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('publish_state', PublishState::Published->value);
    }

    protected static function newFactory(): ModuleFactory
    {
        return ModuleFactory::new();
    }
}
