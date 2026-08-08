<?php

declare(strict_types=1);

use App\Platform\Shared\Tenancy\Concerns\BelongsToTenantNullable;
use App\Platform\Shared\Tenancy\SharedOrOwnedTenantScope;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proves the Option-N "global-OR-own-org" (nullable) tenancy kernel on a throwaway model, without
 * touching any production model. NULL owner = global/public platform content; a non-null owner = a
 * single organization's private content.
 */
class SharedOrOwnedTestModel extends Model
{
    use BelongsToTenantNullable;

    protected $table = 'shared_or_owned_test';

    public $timestamps = false;

    protected $guarded = [];
}

beforeEach(function (): void {
    Schema::create('shared_or_owned_test', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('organization_id')->nullable();
        $table->string('name');
    });

    app(TenantContext::class)->forget();
});

afterEach(function (): void {
    Schema::dropIfExists('shared_or_owned_test');
    app(TenantContext::class)->forget();
});

function seedSharedOrOwned(): void
{
    SharedOrOwnedTestModel::insert([
        ['organization_id' => null, 'name' => 'global'],
        ['organization_id' => 1, 'name' => 'org1'],
        ['organization_id' => 2, 'name' => 'org2'],
    ]);
}

it('does not filter when no tenant is resolved (backward compatible)', function (): void {
    seedSharedOrOwned();

    expect(SharedOrOwnedTestModel::count())->toBe(3);
});

it('shows global rows PLUS the active tenant rows, never another tenant', function (): void {
    seedSharedOrOwned();

    app(TenantContext::class)->set(TenantId::from(1));

    expect(SharedOrOwnedTestModel::orderBy('name')->pluck('name')->all())->toBe(['global', 'org1']);
});

it('never leaks another organization private row to the active tenant', function (): void {
    seedSharedOrOwned();

    app(TenantContext::class)->set(TenantId::from(1));

    expect(SharedOrOwnedTestModel::where('name', 'org2')->exists())->toBeFalse();
});

it('stamps the active tenant on create, but leaves it NULL (global) with no tenant', function (): void {
    app(TenantContext::class)->set(TenantId::from(7));
    $owned = SharedOrOwnedTestModel::create(['name' => 'x']);
    expect((int) $owned->organization_id)->toBe(7);

    app(TenantContext::class)->forget();
    $global = SharedOrOwnedTestModel::create(['name' => 'y']);
    expect($global->organization_id)->toBeNull()
        ->and($global->isGlobal())->toBeTrue();
});

it('classifies visibility and ownership correctly', function (): void {
    $global = new SharedOrOwnedTestModel(['organization_id' => null]);
    $org1 = new SharedOrOwnedTestModel(['organization_id' => 1]);

    expect($global->isVisibleToTenant(TenantId::from(1)))->toBeTrue()
        ->and($global->belongsToTenant(TenantId::from(1)))->toBeFalse()
        ->and($org1->isVisibleToTenant(TenantId::from(1)))->toBeTrue()
        ->and($org1->isVisibleToTenant(TenantId::from(2)))->toBeFalse()
        ->and($org1->belongsToTenant(TenantId::from(1)))->toBeTrue();
});

it('bypasses to see everything within runWithoutTenancy', function (): void {
    seedSharedOrOwned();
    app(TenantContext::class)->set(TenantId::from(1));

    $count = app(TenantContext::class)->runWithoutTenancy(
        static fn (): int => SharedOrOwnedTestModel::count(),
    );

    expect($count)->toBe(3);
});

it('is removable per query via withoutGlobalScope', function (): void {
    seedSharedOrOwned();
    app(TenantContext::class)->set(TenantId::from(1));

    expect(SharedOrOwnedTestModel::withoutGlobalScope(SharedOrOwnedTenantScope::class)->count())->toBe(3);
});

it('supports explicit forTenant (global + own) and ownedByTenant (own only) scopes', function (): void {
    seedSharedOrOwned();

    app(TenantContext::class)->runWithoutTenancy(function (): void {
        expect(SharedOrOwnedTestModel::forTenant(TenantId::from(1))->orderBy('name')->pluck('name')->all())
            ->toBe(['global', 'org1'])
            ->and(SharedOrOwnedTestModel::ownedByTenant(TenantId::from(1))->pluck('name')->all())
            ->toBe(['org1']);
    });
});
