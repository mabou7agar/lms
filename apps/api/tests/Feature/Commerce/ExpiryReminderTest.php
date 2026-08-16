<?php

declare(strict_types=1);

use App\Contexts\Commerce\Enums\CompanyCertificateBranding;
use App\Contexts\Commerce\Enums\SeatMode;
use App\Contexts\Commerce\Models\CompanyEntitlement;
use App\Contexts\Commerce\Services\ExpiryReminderService;
use App\Domains\Certification\Actions\GenerateCertificateAction;
use App\Domains\Crm\Models\Organization;
use App\Platform\Identity\Models\User;
use App\Platform\Notifications\Models\Notification;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once __DIR__.'/CertificateHelpers.php';

beforeEach(fn () => app(TenantContext::class)->forget());
afterEach(fn () => app(TenantContext::class)->forget());

/** A company purchase whose access window closes in `$days` days, with `$offsets` as its cadence. */
function expiringCompanyPurchase(int $days, array $offsets = [30, 7]): array
{
    [$product, $course] = certificateProduct([
        'audience' => 'company',
        'seat_mode' => SeatMode::Fixed->value,
        'default_seat_count' => 5,
        'reminder_offsets_days' => $offsets,
        'company_certificate_branding' => CompanyCertificateBranding::HelbaronOnly->value,
    ]);

    $org = Organization::factory()->create(['name' => 'Lapsing Ltd']);
    $owner = certificateCompanyOwner($org);
    paidOrderFor($product, $owner, $org);

    $entitlement = CompanyEntitlement::where('organization_id', $org->id)->firstOrFail();
    $entitlement->forceFill(['access_ends_at' => now()->addDays($days)])->save();

    $employee = seatedEmployee($org, $entitlement->refresh(), 'staff@lapsing.test');

    return [$entitlement->refresh(), $owner, $employee, $course];
}

it('warns the company managers and the seated employees at a configured offset', function (): void {
    // 6 days left crosses the 7-day offset but not the 30-day one, which has already been passed.
    [, $owner, $employee] = expiringCompanyPurchase(6, [30, 7]);

    $sent = app(ExpiryReminderService::class)->sweep();

    expect($sent['purchases'])->toBe(1)
        ->and($sent['seats'])->toBe(1);

    expect(Notification::where('user_id', $owner->id)->where('type', 'company_purchase_expiring')->exists())->toBeTrue()
        ->and(Notification::where('user_id', $employee->id)->where('type', 'seat_access_expiring')->exists())->toBeTrue();
});

it('sends nothing while the purchase is still outside every configured offset', function (): void {
    // 20 days left: past the 7-day mark but the widest configured offset is 10.
    [, $owner] = expiringCompanyPurchase(20, [10, 3]);

    $sent = app(ExpiryReminderService::class)->sweep();

    expect($sent['purchases'])->toBe(0)
        ->and(Notification::where('user_id', $owner->id)->count())->toBe(0);
});

it('does not send the same reminder twice however often the sweep runs', function (): void {
    [, $owner, $employee] = expiringCompanyPurchase(6, [30, 7]);

    $service = app(ExpiryReminderService::class);
    $service->sweep();
    $service->sweep();
    $service->sweep();

    // The dedup key is (kind, reference, recipient, offset), so three runs leave one notice each.
    expect(Notification::where('user_id', $owner->id)->where('type', 'company_purchase_expiring')->count())->toBe(1)
        ->and(Notification::where('user_id', $employee->id)->where('type', 'seat_access_expiring')->count())->toBe(1);
});

it('sends a second, distinct reminder as a nearer offset comes due', function (): void {
    [$entitlement, $owner] = expiringCompanyPurchase(6, [7, 1]);

    app(ExpiryReminderService::class)->sweep();

    // The window narrows to the 1-day offset — a genuinely different event.
    $entitlement->forceFill(['access_ends_at' => now()->addHours(12)])->save();
    app(ExpiryReminderService::class)->sweep();

    expect(Notification::where('user_id', $owner->id)->where('type', 'company_purchase_expiring')->count())->toBe(2);
});

it('leaves employees alone when their access outlives the purchase', function (): void {
    [$entitlement, , $employee] = expiringCompanyPurchase(6, [30, 7]);
    $entitlement->forceFill(['employee_access_expires_with_purchase' => false])->save();

    $sent = app(ExpiryReminderService::class)->sweep();

    expect($sent['seats'])->toBe(0)
        ->and(Notification::where('user_id', $employee->id)->where('type', 'seat_access_expiring')->exists())->toBeFalse();
});

it('warns a learner whose certificate is about to lapse', function (): void {
    [$product, $course] = certificateProduct(['reminder_offsets_days' => [30, 7]]);
    $learner = User::factory()->create();
    paidOrderFor($product, $learner);

    $certificate = app(GenerateCertificateAction::class)->executeByUserId($learner->id, $course);
    $certificate->forceFill(['expires_at' => now()->addDays(5)])->save();

    $sent = app(ExpiryReminderService::class)->sweep();

    expect($sent['certificates'])->toBe(1)
        ->and(Notification::where('user_id', $learner->id)->where('type', 'certificate_expiring')->count())->toBe(1);

    // Idempotent for the same offset.
    app(ExpiryReminderService::class)->sweep();
    expect(Notification::where('user_id', $learner->id)->where('type', 'certificate_expiring')->count())->toBe(1);
});

it('says nothing about a certificate that has already lapsed', function (): void {
    [$product, $course] = certificateProduct(['reminder_offsets_days' => [30]]);
    $learner = User::factory()->create();
    paidOrderFor($product, $learner);

    $certificate = app(GenerateCertificateAction::class)->executeByUserId($learner->id, $course);
    $certificate->forceFill(['expires_at' => now()->subDay()])->save();

    expect(app(ExpiryReminderService::class)->sweep()['certificates'])->toBe(0);
});

it('runs the scheduled command without error', function (): void {
    expiringCompanyPurchase(6, [30, 7]);

    $this->artisan('commerce:send-expiry-reminders')
        ->expectsOutputToContain('Expiry reminders:')
        ->assertSuccessful();
});
