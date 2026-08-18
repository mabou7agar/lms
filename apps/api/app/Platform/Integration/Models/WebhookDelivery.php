<?php

namespace App\Platform\Integration\Models;

use App\Platform\Integration\Database\Factories\WebhookDeliveryFactory;
use App\Platform\Integration\Enums\DeliveryStatus;
use App\Platform\Shared\Tenancy\Concerns\BelongsToTenant;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt-tracked delivery of an event to an endpoint. Tenant-scoped via organization_id (mirrored
 * from the owning endpoint) AND reachable transitively through the endpoint relation.
 *
 * @property int $id
 * @property string $public_id
 * @property int $webhook_endpoint_id
 * @property int|null $organization_id
 * @property string $event_type
 * @property string $event_id
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property int|null $response_status
 * @property int|null $response_ms
 * @property string|null $error
 * @property string|null $signature
 * @property Carbon|null $delivered_at
 * @property Carbon|null $next_retry_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WebhookEndpoint|null $webhookEndpoint
 */
class WebhookDelivery extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;
    use HasPublicId;

    protected $table = 'webhook_deliveries';

    protected $fillable = [
        'webhook_endpoint_id', 'event_type', 'event_id', 'payload', 'status', 'attempts',
        'response_status', 'response_ms', 'error', 'signature', 'delivered_at', 'next_retry_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'response_status' => 'integer',
            'response_ms' => 'integer',
            'delivered_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function webhookEndpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class);
    }

    public function statusEnum(): DeliveryStatus
    {
        return DeliveryStatus::from($this->status);
    }

    protected static function newFactory(): WebhookDeliveryFactory
    {
        return WebhookDeliveryFactory::new();
    }
}
