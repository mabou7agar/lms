<?php

namespace App\Platform\Notifications\Http\Requests;

use App\Platform\Notifications\Enums\Channel;
use App\Platform\Notifications\Enums\DigestFrequency;
use App\Platform\Notifications\Enums\NotificationCategory;
use App\Platform\Shared\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferencesRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'locale' => ['sometimes', 'in:en,ar'],
            'digest_frequency' => ['sometimes', Rule::in(DigestFrequency::values())],
            'timezone' => ['sometimes', 'string', 'max:64'],
            // Quiet hours: a marketing-category message inside this window is deferred (transactional
            // messages always send). Times are wall-clock HH:MM (or HH:MM:SS) in the user's timezone.
            'quiet_hours_enabled' => ['sometimes', 'boolean'],
            'quiet_hours_start' => ['sometimes', 'nullable', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'quiet_hours_end' => ['sometimes', 'nullable', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'preferences' => ['sometimes', 'array'],
            'preferences.*.category' => ['required_with:preferences', Rule::in(NotificationCategory::values())],
            'preferences.*.channel' => ['required_with:preferences', Rule::in(Channel::values())],
            'preferences.*.enabled' => ['required_with:preferences', 'boolean'],
        ];
    }
}
