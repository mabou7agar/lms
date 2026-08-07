<?php

use App\Platform\Identity\Enums\ConsentPurpose;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Models\UserConsent;
use App\Platform\Identity\Services\ConsentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records a grant then a withdrawal as a single upserted row', function () {
    $user = User::factory()->create();
    $consents = app(ConsentManager::class);

    $consents->record($user, ConsentPurpose::Marketing, true, 'v1', '127.0.0.1');
    expect($consents->has($user, ConsentPurpose::Marketing))->toBeTrue();

    $row = UserConsent::query()->where('user_id', $user->id)->where('purpose', 'marketing')->firstOrFail();
    expect($row->granted)->toBeTrue()->and($row->granted_at)->not->toBeNull()->and($row->revoked_at)->toBeNull();

    $consents->record($user, ConsentPurpose::Marketing, false);
    expect($consents->has($user, ConsentPurpose::Marketing))->toBeFalse();

    $row->refresh();
    expect($row->granted)->toBeFalse()->and($row->revoked_at)->not->toBeNull()
        ->and(UserConsent::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('reports every purpose, defaulting undecided ones to false', function () {
    $user = User::factory()->create();
    $consents = app(ConsentManager::class);

    $consents->record($user, ConsentPurpose::Analytics, true);

    $all = $consents->all($user);
    expect($all['analytics'])->toBeTrue()
        ->and($all['marketing'])->toBeFalse()
        ->and($all)->toHaveKey('terms')
        ->and($all)->toHaveKey('privacy_policy');
});
