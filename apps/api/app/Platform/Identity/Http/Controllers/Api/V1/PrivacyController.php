<?php

namespace App\Platform\Identity\Http\Controllers\Api\V1;

use App\Platform\Identity\Actions\Privacy\ExportUserDataAction;
use App\Platform\Identity\Actions\Privacy\SubmitDataRequestAction;
use App\Platform\Identity\Enums\ConsentPurpose;
use App\Platform\Identity\Enums\DataRequestType;
use App\Platform\Identity\Http\Requests\RecordConsentRequest;
use App\Platform\Identity\Http\Requests\SubmitDataRequestRequest;
use App\Platform\Identity\Models\DataSubjectRequest;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Services\ConsentManager;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Self-service privacy surface (PDPL/GDPR): read/record consent, submit data-subject requests, and
 * export the data Identity holds. All actions are scoped to the authenticated user.
 */
class PrivacyController extends Controller
{
    public function consents(Request $request, ConsentManager $consents): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(['consents' => $consents->all($user)]);
    }

    public function recordConsent(RecordConsentRequest $request, ConsentManager $consents): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $consents->record(
            $user,
            ConsentPurpose::from((string) $data['purpose']),
            (bool) $data['granted'],
            $data['version'] ?? null,
            $request->ip(),
        );

        return ApiResponse::success(['consents' => $consents->all($user)], 'Consent updated.');
    }

    public function export(Request $request, ExportUserDataAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success($action->execute($user));
    }

    public function listDataRequests(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $requests = DataSubjectRequest::query()
            ->where('user_id', $user->id)
            ->latest('requested_at')
            ->get()
            ->map(fn (DataSubjectRequest $r): array => [
                'id' => $r->public_id,
                'type' => $r->type->value,
                'status' => $r->status->value,
                'requested_at' => $r->requested_at?->toIso8601String(),
                'completed_at' => $r->completed_at?->toIso8601String(),
            ]);

        return ApiResponse::success($requests);
    }

    public function submitDataRequest(SubmitDataRequestRequest $request, SubmitDataRequestAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $dsr = $action->execute($user, DataRequestType::from((string) $data['type']), $data['note'] ?? null);

        return ApiResponse::created([
            'id' => $dsr->public_id,
            'type' => $dsr->type->value,
            'status' => $dsr->status->value,
            'requested_at' => $dsr->requested_at?->toIso8601String(),
        ], 'Your request has been logged.');
    }
}
