<?php

namespace App\Platform\Notifications\Actions;

use App\Platform\Notifications\Models\NotificationPreference;
use App\Platform\Notifications\Models\UserNotificationSetting;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Updates a user's notification settings (locale, digest) and per-category/channel preferences.
 */
class UpdatePreferencesAction extends BaseAction
{
    /**
     * @param  array{locale?: string, digest_frequency?: string, timezone?: string, quiet_hours_enabled?: bool, quiet_hours_start?: string|null, quiet_hours_end?: string|null, preferences?: array<int, array{category: string, channel: string, enabled: bool}>}  $data
     */
    public function executeForUserId(int $userId, array $data): UserNotificationSetting
    {
        return $this->transaction(function () use ($userId, $data): UserNotificationSetting {
            $settingData = array_filter([
                'locale' => $data['locale'] ?? null,
                'digest_frequency' => $data['digest_frequency'] ?? null,
                'timezone' => $data['timezone'] ?? null,
            ], fn ($v) => $v !== null);

            // Quiet-hours keys are applied whenever supplied (including false/null, so the window can be
            // disabled or cleared) — array_filter above would otherwise drop those legitimate values.
            foreach (['quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end'] as $key) {
                if (array_key_exists($key, $data)) {
                    $settingData[$key] = $data[$key];
                }
            }

            $setting = UserNotificationSetting::updateOrCreate(['user_id' => $userId], $settingData);

            foreach ($data['preferences'] ?? [] as $pref) {
                NotificationPreference::updateOrCreate(
                    ['user_id' => $userId, 'category' => $pref['category'], 'channel' => $pref['channel']],
                    ['enabled' => (bool) $pref['enabled']],
                );
            }

            return $setting;
        });
    }
}
