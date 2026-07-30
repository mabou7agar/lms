<?php

namespace App\Contexts\Commerce\Http\Controllers\Api\V1;

use App\Contexts\Commerce\Actions\Subscription\CancelSubscriptionAction;
use App\Contexts\Commerce\Actions\Subscription\ChangePlanAction;
use App\Contexts\Commerce\Actions\Subscription\ReactivateSubscriptionAction;
use App\Contexts\Commerce\Actions\Subscription\SubscribeAction;
use App\Contexts\Commerce\Http\Resources\SubscriptionResource;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Contexts\Commerce\Services\SubscriptionService;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Learner subscription endpoints. Thin: resolve the authenticated user, validate input, delegate to
 * a domain Action or read Service, and shape the result through SubscriptionResource. Ownership is
 * enforced by scoping every subscription lookup to the authenticated user's id, so a user can only
 * ever read or mutate their own subscriptions. No persistence or business logic lives here.
 */
class SubscriptionController extends Controller
{
    public function index(Request $request, SubscriptionService $service): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 15), 100));

        $subscriptions = $service->listForUser($this->userId($request), $perPage);

        return ApiResponse::paginated($subscriptions, SubscriptionResource::class);
    }

    public function store(Request $request, SubscribeAction $action): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $plan = SubscriptionPlan::query()
            ->where('public_id', $validated['plan'])
            ->where('is_active', true)
            ->firstOrFail();

        $subscription = $action->execute(
            $this->userId($request),
            $plan,
            $validated['currency'] ?? null,
        );

        return ApiResponse::success(new SubscriptionResource($subscription->load('plan')), null, 201);
    }

    public function cancel(Request $request, string $subscription, CancelSubscriptionAction $action): JsonResponse
    {
        $validated = $request->validate([
            'at_period_end' => ['nullable', 'boolean'],
        ]);

        $model = $this->resolveOwned($request, $subscription);

        $result = $action->execute($model, (bool) ($validated['at_period_end'] ?? true));

        return ApiResponse::success(new SubscriptionResource($result->load('plan')));
    }

    public function reactivate(Request $request, string $subscription, ReactivateSubscriptionAction $action): JsonResponse
    {
        $model = $this->resolveOwned($request, $subscription);

        $result = $action->execute($model);

        return ApiResponse::success(new SubscriptionResource($result->load('plan')));
    }

    public function change(Request $request, string $subscription, ChangePlanAction $action): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $model = $this->resolveOwned($request, $subscription);

        $newPlan = SubscriptionPlan::query()
            ->where('public_id', $validated['plan'])
            ->where('is_active', true)
            ->firstOrFail();

        $result = $action->execute($model, $newPlan, $validated['currency'] ?? null);

        return ApiResponse::success(new SubscriptionResource($result->load('plan')));
    }

    /** Resolve a subscription by public id, scoped to the authenticated user (404 otherwise). */
    private function resolveOwned(Request $request, string $publicId): Subscription
    {
        return Subscription::query()
            ->where('public_id', $publicId)
            ->where('user_id', $this->userId($request))
            ->firstOrFail();
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }
}
