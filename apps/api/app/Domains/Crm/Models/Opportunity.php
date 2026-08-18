<?php

namespace App\Domains\Crm\Models;

use App\Domains\Crm\Concerns\HasActivities;
use App\Domains\Crm\Concerns\HasNotes;
use App\Domains\Crm\Concerns\HasTasks;
use App\Domains\Crm\Enums\OpportunityStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int|null $amount_minor
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property string|null $currency
 * @property Carbon|null $expected_close_date
 * @property string|null $lost_reason
 * @property string $name
 * @property int|null $owner_id
 * @property int|null $pipeline_id
 * @property int $probability
 * @property string|null $product_ref
 * @property string $public_id
 * @property OpportunityStatus $status
 * @property Carbon|null $won_at
 */
class Opportunity extends Model
{
    use HasActivities;
    use HasNotes;
    use HasPublicId;
    use HasTasks;
    use SoftDeletes;

    protected $table = 'crm_opportunities';

    protected $fillable = [
        'lead_id', 'company_id', 'organization_id', 'pipeline_id', 'stage_id', 'owner_id',
        'name', 'amount_minor', 'currency', 'status', 'probability', 'product_ref',
        'expected_close_date', 'lost_reason', 'won_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OpportunityStatus::class,
            'amount_minor' => 'integer',
            'probability' => 'integer',
            'expected_close_date' => 'date',
            'won_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /**
     * Owning user. Resolved via auth config (not a concrete Identity import) so CRM keeps
     * no compile-time dependency on the Identity context.
     *
     * @return BelongsTo<Model, $this>
     */
    public function owner(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');

        return $this->belongsTo($userModel, 'owner_id');
    }
}
