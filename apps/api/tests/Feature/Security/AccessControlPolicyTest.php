<?php

use App\Contexts\Commerce\Models\Order;
use App\Domains\Catalog\Models\Course;
use App\Domains\Certification\Actions\GenerateCertificateAction;
use App\Domains\Certification\Database\Seeders\CertificationSeeder;
use App\Domains\Certification\Models\Certificate;
use App\Domains\Certification\Models\CertificateTemplate;
use App\Domains\Live\Database\Seeders\LiveSeeder;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * M10 — LiveSessionPolicy / CertificatePolicy used `$user->can()`, which under auth:sanctum resolves
 * the wrong guard and answers false even for a genuine permission holder — so live-session
 * management and certificate revoke/reissue were unreachable for anyone but super_admin. The fix is
 * hasPermission(). These tests prove the two actions are now reachable by a permission-holding
 * non-super-admin, still denied without the permission, and unchanged for super_admin and owners.
 */

// ---------------------------------------------------------------- LiveSessionPolicy::manage

function schedulePayload(): array
{
    return [
        'title' => 'Workshop',
        'timezone' => 'UTC',
        'starts_at' => now()->addWeek()->format('Y-m-d 18:00'),
        'duration_minutes' => 60,
    ];
}

it('lets a permission-holding non-super-admin manage live sessions', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(LiveSeeder::class); // grants live.sessions.manage to the `admin` role

    $manager = User::factory()->create();
    $manager->assignRole('admin'); // NOT super_admin — exercises manage()/hasPermission()
    Sanctum::actingAs($manager);

    $this->postJson('/api/v1/admin/live-sessions', schedulePayload())->assertCreated();
});

it('denies live-session management to a user without the permission', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(LiveSeeder::class);

    $student = User::factory()->create();
    $student->assignRole('student'); // no live permission
    Sanctum::actingAs($student);

    $this->postJson('/api/v1/admin/live-sessions', schedulePayload())->assertForbidden();
});

it('preserves super-admin access to live-session management', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(LiveSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/admin/live-sessions', schedulePayload())->assertCreated();
});

// ---------------------------------------------------------------- CertificatePolicy::manage / view

function issueCertificateFor(User $holder): Certificate
{
    // CertificationSeeder already seeds the active (key=default, version=1) template, so a blind
    // factory insert collides on the unique (key, version). Reuse the factory's shape but persist
    // idempotently: reuse the seeded row when present, create it (active) when it is not.
    CertificateTemplate::firstOrCreate(
        ['key' => 'default', 'version' => 1],
        CertificateTemplate::factory()->raw(['is_active' => true]),
    );

    return app(GenerateCertificateAction::class)
        ->executeByUserId($holder->id, Course::factory()->published()->create());
}

it('lets a permission-holding non-super-admin revoke a certificate', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CertificationSeeder::class); // grants certification.certificates.manage to `admin`

    $cert = issueCertificateFor(User::factory()->create());

    $manager = User::factory()->create();
    $manager->assignRole('admin'); // NOT super_admin
    Sanctum::actingAs($manager);

    $this->postJson("/api/v1/admin/certificates/{$cert->public_id}/revoke")
        ->assertOk()->assertJsonPath('data.status', 'revoked');
});

it('denies certificate management to a user without the permission', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CertificationSeeder::class);

    $cert = issueCertificateFor(User::factory()->create());

    $stranger = User::factory()->create();
    $stranger->assignRole('student');
    Sanctum::actingAs($stranger);

    $this->postJson("/api/v1/admin/certificates/{$cert->public_id}/revoke")->assertForbidden();
});

it('preserves super-admin access to certificate management', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CertificationSeeder::class);

    $cert = issueCertificateFor(User::factory()->create());

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/admin/certificates/{$cert->public_id}/revoke")->assertOk();
});

it('still lets a certificate owner view their own certificate (no regression)', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CertificationSeeder::class);

    $owner = User::factory()->create();
    $cert = issueCertificateFor($owner);
    Sanctum::actingAs($owner);

    // The owner path in CertificatePolicy::view (actorId match) is unchanged by the can()→hasPermission fix.
    $this->getJson("/api/v1/certificates/{$cert->public_id}")->assertOk();
});

it('denies viewing another user\'s certificate without the manage permission', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CertificationSeeder::class);

    $cert = issueCertificateFor(User::factory()->create());

    $other = User::factory()->create();
    $other->assignRole('student');
    Sanctum::actingAs($other);

    $this->getJson("/api/v1/certificates/{$cert->public_id}")->assertForbidden();
});

// ---------------------------------------------------------------- RefundController::commerce.refunds.manage
// Refunds are a P0 financial action guarded by can:commerce.refunds.manage. Lock the guard so a
// future refactor that drops the middleware cannot silently expose refunds to unprivileged users.
it('denies issuing a refund to a user without the refunds.manage permission', function () {
    $order = Order::factory()->create();
    $user = User::factory()->create(); // no roles / no permissions

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/admin/orders/{$order->getRouteKey()}/refund", ['amount_minor' => 500])
        ->assertForbidden();
});
