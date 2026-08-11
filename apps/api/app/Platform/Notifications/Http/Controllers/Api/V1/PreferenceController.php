<?php

namespace App\Platform\Notifications\Http\Controllers\Api\V1;

use App\Platform\Notifications\Actions\UpdatePreferencesAction;
use App\Platform\Notifications\Http\Requests\UpdatePreferencesRequest;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class PreferenceController extends Controller
{
    public function update(UpdatePreferencesRequest $request, UpdatePreferencesAction $action): JsonResponse
    {
        $setting = $action->executeForUserId($request->user()->id, $request->validated());

        return ApiResponse::updated([
            'locale' => $setting->locale,
            'digest_frequency' => $setting->digest_frequency->value,
            'timezone' => $setting->timezone,
            'quiet_hours_enabled' => (bool) $setting->quiet_hours_enabled,
            'quiet_hours_start' => $setting->quiet_hours_start,
            'quiet_hours_end' => $setting->quiet_hours_end,
        ], 'Preferences updated.');
    }
}
