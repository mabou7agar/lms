<?php

namespace App\Domains\Crm\Models;

use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property mixed $is_lost
 * @property bool $is_won
 * @property string $name
 * @property int $pipeline_id
 */
class Stage extends Model
{
    use HasPublicId;

    protected $table = 'crm_stages';

    protected $fillable = ['pipeline_id', 'name', 'position', 'is_won', 'is_lost'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_won' => 'boolean', 'is_lost' => 'boolean'];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }
}
