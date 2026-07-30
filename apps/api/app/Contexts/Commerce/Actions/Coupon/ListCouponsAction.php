<?php

namespace App\Contexts\Commerce\Actions\Coupon;

use App\Contexts\Commerce\Models\Coupon;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Lists coupons for the admin catalogue, newest first.
 */
class ListCouponsAction extends BaseAction
{
    /** @return LengthAwarePaginator<int, Coupon> */
    public function execute(int $perPage = 20): LengthAwarePaginator
    {
        return Coupon::query()->latest('id')->paginate($perPage);
    }
}
