<?php

namespace App\Contexts\Commerce\Actions\Coupon;

use App\Contexts\Commerce\Models\Coupon;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Updates an existing coupon (resolved by public id) from an already-validated attribute set.
 */
class UpdateCouponAction extends BaseAction
{
    /** @param array<string, mixed> $data */
    public function execute(string $publicId, array $data): Coupon
    {
        return $this->transaction(function () use ($publicId, $data): Coupon {
            $coupon = Coupon::query()->where('public_id', $publicId)->firstOrFail();
            $coupon->fill($data)->save();

            return $coupon->refresh();
        });
    }
}
