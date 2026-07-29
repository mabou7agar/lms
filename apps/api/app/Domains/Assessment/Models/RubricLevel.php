<?php

namespace App\Domains\Assessment\Models;

use App\Domains\Assessment\Database\Factories\RubricLevelFactory;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One selectable performance level within a criterion.
 *
 * @property int $id
 * @property string $public_id
 * @property int $criterion_id
 * @property string $title
 * @property string|null $description
 * @property string $points decimal:2
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read RubricCriterion|null $criterion
 */
class RubricLevel extends Model
{
    /** @use HasFactory<RubricLevelFactory> */
    use HasFactory;

    use HasPublicId;

    /** @var list<string> */
    protected $fillable = ['criterion_id', 'title', 'description', 'points', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['points' => 'decimal:2', 'position' => 'integer'];
    }

    /** @return BelongsTo<RubricCriterion, $this> */
    public function criterion(): BelongsTo
    {
        return $this->belongsTo(RubricCriterion::class, 'criterion_id');
    }

    protected static function newFactory(): RubricLevelFactory
    {
        return RubricLevelFactory::new();
    }
}
