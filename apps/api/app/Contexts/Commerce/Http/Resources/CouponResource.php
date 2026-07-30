<?php

namespace App\Contexts\Commerce\Http\Resources;

use App\Contexts\Commerce\Enums\CouponScope;
use App\Contexts\Commerce\Enums\CouponType;
use App\Contexts\Commerce\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Coupon $resource
 */
class CouponResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $c = $this->resource;
        $type = $c->getAttribute('type');
        $scope = $c->getAttribute('scope');

        return [
            'id' => $c->getAttribute('public_id'),
            'code' => $c->getAttribute('code'),
            'type' => $type instanceof CouponType ? $type->value : (string) $type,
            'value' => (int) $c->getAttribute('value'),
            'scope' => $scope instanceof CouponScope ? $scope->value : (string) $scope,
            'currency' => $c->getAttribute('currency'),
            'max_redemptions' => $c->getAttribute('max_redemptions'),
            'redeemed_count' => (int) $c->getAttribute('redeemed_count'),
            'per_user_limit' => $c->getAttribute('per_user_limit'),
            'first_order_only' => (bool) $c->getAttribute('first_order_only'),
            'min_subtotal_minor' => $c->getAttribute('min_subtotal_minor'),
            'stackable' => (bool) $c->getAttribute('stackable'),
            'starts_at' => $c->getAttribute('starts_at')?->toIso8601String(),
            'ends_at' => $c->getAttribute('ends_at')?->toIso8601String(),
            'is_active' => (bool) $c->getAttribute('is_active'),
        ];
    }
}
