<?php

namespace App\Contexts\Commerce\Actions\Coupon;

use App\Contexts\Commerce\Models\Coupon;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Creates a coupon from an already-validated attribute set. Persistence lives here (never in the
 * controller). Money bounds (min_subtotal_minor, fixed value) are integer minor units.
 */
class CreateCouponAction extends BaseAction
{
    /** @param array<string, mixed> $data */
    public function execute(array $data): Coupon
    {
        return $this->transaction(fn (): Coupon => Coupon::create($data));
    }
}
