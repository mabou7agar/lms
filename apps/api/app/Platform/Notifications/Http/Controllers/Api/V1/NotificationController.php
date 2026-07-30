<?php

namespace App\Platform\Notifications\Http\Controllers\Api\V1;

use App\Platform\Notifications\Actions\MarkNotificationReadAction;
use App\Platform\Notifications\Http\Resources\NotificationResource;
use App\Platform\Notifications\Models\Notification;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::query()
            ->where('user_id', $request->user()->id)
            ->active()
            ->orderByRaw('read_at IS NOT NULL')
            ->latest('id')
            ->paginate(max(1, min((int) $request->input('per_page', 20), 100)))->withQueryString();

        return ApiResponse::paginated($notifications, NotificationResource::class);
    }

    public function show(Notification $notification): JsonResponse
    {
        Gate::authorize('view', $notification);

        return ApiResponse::success(new NotificationResource($notification->load('deliveries')));
    }

    public function read(Notification $notification, MarkNotificationReadAction $action): JsonResponse
    {
        Gate::authorize('view', $notification);

        return ApiResponse::updated(new NotificationResource($action->execute($notification)), 'Marked as read.');
    }
}
