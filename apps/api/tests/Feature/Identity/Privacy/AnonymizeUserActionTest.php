<?php

use App\Platform\Identity\Actions\Privacy\AnonymizeUserAction;
use App\Platform\Identity\Enums\ConsentPurpose;
use App\Platform\Identity\Models\DataSubjectRequest;
use App\Platform\Identity\Models\SocialAccount;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Models\UserConsent;
use App\Platform\Identity\Services\ConsentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

it('pseudonymises the account, preserves its id, and revokes all access', function () {
    $user = User::factory()->create(['email' => 'real@ex.test', 'name' => 'Real Name', 'phone' => '+966500000001']);
    $id = $user->id;

    $user->createToken('device');
    app(ConsentManager::class)->record($user, ConsentPurpose::Marketing, true, 'v1', '1.2.3.4');
    SocialAccount::create(['user_id' => $id, 'provider' => 'fake', 'provider_subject' => 'sub', 'email' => 'real@ex.test']);
    DataSubjectRequest::create(['user_id' => $id, 'type' => 'erasure', 'status' => 'pending', 'requested_at' => now()]);

    app(AnonymizeUserAction::class)->execute($user);

    $fresh = User::withTrashed()->find($id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->trashed())->toBeTrue()
        ->and($fresh->name)->toBe('Deleted user')
        ->and($fresh->email)->not->toBe('real@ex.test')
        ->and($fresh->phone)->toBeNull()
        ->and($fresh->is_active)->toBeFalse();

    expect(PersonalAccessToken::query()->where('tokenable_id', $id)->count())->toBe(0)
        ->and(UserConsent::query()->where('user_id', $id)->count())->toBe(0)
        ->and(SocialAccount::query()->where('user_id', $id)->count())->toBe(0);

    $erasure = DataSubjectRequest::query()->where('user_id', $id)->where('type', 'erasure')->firstOrFail();
    expect($erasure->status->value)->toBe('completed')->and($erasure->completed_at)->not->toBeNull();
});
