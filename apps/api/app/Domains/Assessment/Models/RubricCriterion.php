<?php

namespace App\Domains\Assessment\Models;

use App\Domains\Assessment\Database\Factories\RubricCriterionFactory;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One criterion (row) of a rubric. max_points is the highest level beneath it.
 *
 * @property int $id
 * @property string $public_id
 * @property int $rubric_id
 * @property string $title
 * @property string|null $description
 * @property int $position
 * @property string $max_points decimal:2
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AssignmentRubric|null $rubric
 * @property-read Collection<int, RubricLevel> $levels
 */
class RubricCriterion extends Model
{
    /** @use HasFactory<RubricCriterionFactory> */
    use HasFactory;

    use HasPublicId;

    /** @var list<string> */
    protected $fillable = ['rubric_id', 'title', 'description', 'position', 'max_points'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer', 'max_points' => 'decimal:2'];
    }

    /** @return BelongsTo<AssignmentRubric, $this> */
    public function rubric(): BelongsTo
    {
        return $this->belongsTo(AssignmentRubric::class, 'rubric_id');
    }

    /** @return HasMany<RubricLevel, $this> */
    public function levels(): HasMany
    {
        return $this->hasMany(RubricLevel::class, 'criterion_id')->orderBy('position');
    }

    protected static function newFactory(): RubricCriterionFactory
    {
        return RubricCriterionFactory::new();
    }
}
