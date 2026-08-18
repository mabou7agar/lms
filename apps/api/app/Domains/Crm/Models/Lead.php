<?php

namespace App\Domains\Crm\Models;

use App\Domains\Crm\Concerns\HasActivities;
use App\Domains\Crm\Concerns\HasNotes;
use App\Domains\Crm\Concerns\HasTags;
use App\Domains\Crm\Concerns\HasTasks;
use App\Domains\Crm\Database\Factories\LeadFactory;
use App\Domains\Crm\Enums\LeadStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int|null $company_id
 * @property string|null $company_name
 * @property string|null $company_size
 * @property string|null $country
 * @property string|null $currency
 * @property string|null $email
 * @property Carbon|null $last_contacted_at
 * @property int|null $lead_score
 * @property bool $marketing_consent
 * @property Carbon|null $next_follow_up_at
 * @property int|null $owner_id
 * @property string $public_id
 * @property string|null $request_type
 * @property LeadStatus $status
 * @property string|null $utm_campaign
 * @property string|null $utm_medium
 * @property string|null $utm_source
 * @property int|null $value_minor
 */
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasActivities;

    use HasFactory;
    use HasNotes;
    use HasPublicId;
    use HasTags;
    use HasTasks;
    use SoftDeletes;

    protected $table = 'crm_leads';

    protected $fillable = [
        'pipeline_id', 'stage_id', 'company_id', 'contact_id', 'owner_id',
        'name', 'email', 'phone', 'source', 'status', 'value_minor', 'currency',
        'company_name', 'request_type', 'company_size', 'country',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'gclid', 'referrer', 'landing_path',
        'next_follow_up_at', 'last_contacted_at', 'lead_score',
        'marketing_consent', 'consent_version', 'consented_at', 'consent_ip',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'value_minor' => 'integer',
            'lead_score' => 'integer',
            'marketing_consent' => 'boolean',
            'next_follow_up_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'consented_at' => 'datetime',
        ];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /** @return HasMany<Opportunity, $this> */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
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

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function isConverted(): bool
    {
        return $this->status === LeadStatus::Converted;
    }

    protected static function newFactory(): LeadFactory
    {
        return LeadFactory::new();
    }
}
