<?php

namespace App\Contexts\Commerce\Http\Controllers\Api\V1;

use App\Contexts\Commerce\Actions\Checkout\CheckoutAction;
use App\Contexts\Commerce\Http\Requests\CheckoutRequest;
use App\Contexts\Commerce\Http\Resources\OrderResource;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class CheckoutController extends Controller
{
    public function store(CheckoutRequest $request, CheckoutAction $action): JsonResponse
    {
        $result = $action->executeByUserId($request->user()->id, $request->validated());

        return ApiResponse::created([
            'order' => (new OrderResource($result['order']->load(['items', 'invoice'])))->resolve(),
            'contract_id' => $result['contract']?->public_id,
            'payment' => [
                'provider_reference' => $result['charge']->providerReference,
                'client_secret' => $result['charge']->clientSecret,
                'status' => $result['charge']->status,
            ],
        ], 'Order placed. Accept the contract and complete payment.');
    }
}
