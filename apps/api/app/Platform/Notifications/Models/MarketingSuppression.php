<?php

namespace App\Platform\Notifications\Models;

use App\Platform\Notifications\Enums\SuppressionSource;
use App\Platform\Shared\Tenancy\Concerns\BelongsToTenant;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A recipient's suppression (unsubscribe) for one category within a tenant. Keyed on email so it
 * covers leads and users alike. Consulted for marketing sends only — never for transactional ones.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property string $email
 * @property string $category
 * @property SuppressionSource $source
 * @property Carbon $suppressed_at
 */
class MarketingSuppression extends Model
{
    use BelongsToTenant;
    use HasPublicId;

    protected $fillable = ['organization_id', 'email', 'category', 'source', 'reason', 'suppressed_at'];

    protected function casts(): array
    {
        return [
            'source' => SuppressionSource::class,
            'suppressed_at' => 'datetime',
        ];
    }
}
