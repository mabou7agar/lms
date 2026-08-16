<?php

namespace App\Contexts\Commerce\Http\Requests;

use App\Contexts\Commerce\Enums\BuyerType;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class SetCartBuyerRequest extends BaseFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'buyer_type' => ['required', Rule::in(BuyerType::values())],
            // The organization is resolved server-side from the caller's membership, never trusted
            // from the client — a public id here would let anyone buy in another company's name.
        ];
    }
}
