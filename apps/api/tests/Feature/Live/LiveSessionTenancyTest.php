<?php

declare(strict_types=1);

use App\Domains\Live\Models\LiveSession;
use App\Domains\Live\Models\SessionRegistration;
use App\Platform\Shared\Tenancy\Concerns\BelongsToTenantNullable;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * T1 Option-N adversarial matrix for the Live session root. Sessions are SHARED-OR-OWNED/NULLABLE
 * (public events are global; org-private cohorts get a non-null org). Registrations/attendance stay
 * USER-OWNED — they carry NO tenant column and are never tenant-scoped.
 *
 * The only Live tests that establish a tenant context; the existing Live suite runs NULL-org and is
 * unaffected (scope no-ops, existing rows are global).
 */
beforeEach(function (): void {
    app(TenantContext::class)->forget();
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

/** Create a live session owned by $org (null = global public event), stamped server-side by the trait. */
function sessionForOrg(?int $org, array $attrs = []): LiveSession
{
    $context = app(TenantContext::class);

    if ($org === null) {
        $context->forget();

        return LiveSession::factory()->create($attrs);
    }

    $context->set(TenantId::from($org));
    $session = LiveSession::factory()->create($attrs);
    $context->forget();

    return $session;
}

it('leaves live-session reads unscoped when no tenant is resolved (existing behaviour)', function (): void {
    sessionForOrg(null);
    sessionForOrg(1);
    sessionForOrg(2);

    expect(LiveSession::count())->toBe(3);
});

it('shows an org1 user global public events PLUS org1-private cohorts, never org2-private', function (): void {
    sessionForOrg(null, ['title' => 'Public Event']);
    sessionForOrg(1, ['title' => 'Org1 Cohort']);
    sessionForOrg(2, ['title' => 'Org2 Cohort']);

    app(TenantContext::class)->set(TenantId::from(1));

    expect(LiveSession::orderBy('title')->pluck('title')->all())->toBe(['Org1 Cohort', 'Public Event'])
        ->and(LiveSession::where('title', 'Org2 Cohort')->exists())->toBeFalse();
});

it('stamps organization_id from the resolved tenant and ignores a forged one on create', function (): void {
    app(TenantContext::class)->set(TenantId::from(1));

    $session = LiveSession::create([
        'title' => 'Forged Cohort',
        'status' => 'scheduled',
        'timezone' => 'Asia/Riyadh',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'organization_id' => 2, // not fillable -> dropped -> trait stamps the real tenant (org1)
    ]);

    expect((int) $session->organization_id)->toBe(1);
});

it('creates a GLOBAL public event (organization_id NULL) when no tenant is resolved', function (): void {
    app(TenantContext::class)->forget();

    $session = LiveSession::factory()->create();

    expect($session->organization_id)->toBeNull()
        ->and($session->isGlobal())->toBeTrue();
});

it('keeps registrations USER-OWNED with no tenant column (never tenant-scoped)', function (): void {
    // Structural guarantee: the matrix keeps registrations user-owned, so no organization_id was added.
    expect(Schema::hasColumn('session_registrations', 'organization_id'))->toBeFalse();

    // And the model does not adopt the nullable tenancy trait.
    expect(in_array(
        BelongsToTenantNullable::class,
        class_uses(SessionRegistration::class),
        true,
    ))->toBeFalse();
});
