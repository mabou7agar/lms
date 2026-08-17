<?php

namespace App\Contexts\Commerce\Http\Requests;

use App\Platform\Shared\Requests\BaseFormRequest;

class AddToCartRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'product' => ['required', 'string'],       // product public_id
            'coupon_code' => ['nullable', 'string'],
            // Seats a company chose for a product sold by the seat. Bounds are the product's, so
            // they are checked against it in SeatPurchaseService rather than guessed here.
            'seats' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
