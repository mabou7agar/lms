<?php

namespace App\Domains\Crm\Models;

use App\Domains\Crm\Concerns\HasActivities;
use App\Domains\Crm\Concerns\HasNotes;
use App\Domains\Crm\Concerns\HasTags;
use App\Domains\Crm\Database\Factories\OrganizationFactory;
use App\Domains\Crm\Enums\OrganizationStatus;
use App\Platform\Shared\Traits\HasPublicId;
use App\Platform\Shared\Traits\HasSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CRM corporate account. Distinct from Identity's tenant organization (different table).
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasActivities;

    use HasFactory;
    use HasNotes;
    use HasPublicId;
    use HasSlug;
    use HasTags;
    use SoftDeletes;

    protected $table = 'crm_organizations';

    protected $fillable = ['name', 'slug', 'status', 'size', 'website', 'locale', 'timezone'];

    protected function casts(): array
    {
        return ['status' => OrganizationStatus::class];
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function seatPools(): HasMany
    {
        return $this->hasMany(SeatPool::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }
}
