<?php

use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Notifications\EmailOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

it('verifies email with the emitted OTP', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Omar',
        'email' => 'omar@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertCreated();

    $user = User::where('email', 'omar@example.com')->firstOrFail();

    $code = null;
    Notification::assertSentTo($user, EmailOtpNotification::class, function ($n) use (&$code) {
        $code = $n->code;

        return true;
    });

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/verify-email', ['code' => $code])->assertOk();
    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('rejects a wrong email OTP', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Lina',
        'email' => 'lina@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertCreated();

    $user = User::where('email', 'lina@example.com')->firstOrFail();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/verify-email', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'AUTH_OTP_INVALID');
});

it('restricts an unverified session to profile, email verification, and logout', function () {
    $user = User::factory()->unverified()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonPath('data.email_verified', false);

    $this->putJson('/api/v1/profile', ['name' => 'Not yet'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');
});

it('allows a verified session to use protected API routes', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/profile')->assertOk();
    $this->putJson('/api/v1/profile', ['name' => 'Verified learner'])->assertOk();
});
