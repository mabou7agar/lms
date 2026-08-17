<?php

namespace App\Contexts\Analytics\Models;

use App\Platform\Shared\Analytics\AnalyticsEventName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One thing that happened. Append-only: written once, read many times, never updated — an event is a
 * claim about a moment, and a moment does not change its mind.
 *
 * @property string $name
 * @property int|null $user_id
 * @property int|null $organization_id
 * @property int|null $course_id
 * @property int|null $product_id
 * @property int|null $order_id
 * @property int|null $instructor_id
 * @property string|null $product_type
 * @property string|null $buyer_type
 * @property string|null $session_id
 * @property int|null $value_minor
 * @property array<string, mixed>|null $metadata
 * @property Carbon $occurred_at
 */
class AnalyticsEvent extends Model
{
    protected $table = 'analytics_events';

    protected $fillable = [
        'name', 'user_id', 'organization_id', 'course_id', 'product_id', 'order_id', 'instructor_id',
        'product_type', 'buyer_type', 'utm_source', 'utm_medium', 'utm_campaign', 'session_id',
        'value_minor', 'metadata', 'occurred_at', 'dedup_key',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'value_minor' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNamed(Builder $query, AnalyticsEventName|string $name): Builder
    {
        return $query->where('name', $name instanceof AnalyticsEventName ? $name->value : $name);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('occurred_at', [$from, $to]);
    }
}
