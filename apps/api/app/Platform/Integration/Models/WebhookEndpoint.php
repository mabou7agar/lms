<?php

namespace App\Platform\Integration\Models;

use App\Platform\Integration\Database\Factories\WebhookEndpointFactory;
use App\Platform\Shared\Tenancy\Concerns\BelongsToTenant;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A customer-registered OUTBOUND webhook destination. Tenant-scoped (organization_id) so each org
 * sees and manages only its own endpoints. The signing `secret` is $hidden — it is returned exactly
 * once (on create / rotate) via the API and never serialized again.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $organization_id
 * @property string $name
 * @property string|null $description
 * @property string $url
 * @property string $secret
 * @property array<int, string> $event_types
 * @property bool $active
 * @property int $consecutive_failures
 * @property Carbon|null $disabled_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, WebhookDelivery> $deliveries
 */
class WebhookEndpoint extends Model
{
    // Tenant ownership: filtered to the active tenant and stamped organization_id on create when a
    // tenant is resolved (else NULL = platform-level). Never trusts a client-supplied organization_id.
    use BelongsToTenant;

    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory;

    use HasPublicId;

    protected $table = 'webhook_endpoints';

    protected $fillable = [
        'name', 'description', 'url', 'event_types', 'active', 'created_by',
    ];

    /** The signing secret is never serialized to a client after the one-time reveal. */
    protected $hidden = ['secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_types' => 'array',
            'active' => 'boolean',
            'consecutive_failures' => 'integer',
            'disabled_at' => 'datetime',
        ];
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * @param  Builder<WebhookEndpoint>  $query
     * @return Builder<WebhookEndpoint>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function isSubscribedTo(string $eventName): bool
    {
        return in_array($eventName, $this->event_types, true);
    }

    protected static function newFactory(): WebhookEndpointFactory
    {
        return WebhookEndpointFactory::new();
    }
}
