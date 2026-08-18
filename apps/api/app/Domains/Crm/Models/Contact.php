<?php

namespace App\Domains\Crm\Models;

use App\Domains\Crm\Concerns\HasActivities;
use App\Domains\Crm\Concerns\HasNotes;
use App\Domains\Crm\Concerns\HasTags;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property Carbon|null $created_at
 * @property string|null $email
 * @property string $first_name
 * @property string|null $last_name
 * @property string|null $phone
 * @property string $public_id
 * @property string|null $title
 */
class Contact extends Model
{
    use HasActivities;
    use HasNotes;
    use HasPublicId;
    use HasTags;
    use SoftDeletes;

    protected $table = 'crm_contacts';

    protected $fillable = ['company_id', 'first_name', 'last_name', 'email', 'phone', 'title'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
