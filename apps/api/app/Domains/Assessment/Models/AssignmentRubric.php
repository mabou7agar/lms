<?php

namespace App\Domains\Assessment\Models;

use App\Domains\Assessment\Database\Factories\AssignmentRubricFactory;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A rubric attached to one assignment. total_points is a deterministic roll-up recomputed on build.
 *
 * @property int $id
 * @property string $public_id
 * @property int $assignment_id
 * @property string|null $title
 * @property string $total_points decimal:2
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Assignment|null $assignment
 * @property-read Collection<int, RubricCriterion> $criteria
 */
class AssignmentRubric extends Model
{
    /** @use HasFactory<AssignmentRubricFactory> */
    use HasFactory;

    use HasPublicId;

    /** @var list<string> */
    protected $fillable = ['assignment_id', 'title', 'total_points'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['total_points' => 'decimal:2'];
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return HasMany<RubricCriterion, $this> */
    public function criteria(): HasMany
    {
        return $this->hasMany(RubricCriterion::class, 'rubric_id')->orderBy('position');
    }

    protected static function newFactory(): AssignmentRubricFactory
    {
        return AssignmentRubricFactory::new();
    }
}
