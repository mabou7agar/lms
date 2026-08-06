<?php

use App\Contexts\Commerce\Database\Seeders\CommerceSeeder;
use App\Contexts\Commerce\Filament\Resources\SubscriptionPlanResource;
use App\Contexts\Commerce\Filament\Resources\SubscriptionResource;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Database\Seeders\StaffRoleTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Real roles + real permission slugs: CommerceSeeder registers commerce.* permissions and grants
    // them to admin; StaffRoleTemplatesSeeder builds finance_manager (holds subscriptions.manage) and
    // support_agent (orders.view only) — the finance/support separation under test.
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CommerceSeeder::class);
    $this->seed(StaffRoleTemplatesSeeder::class);
});

function userWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('lets finance_manager manage subscription plans and subscriptions', function () {
    $this->actingAs(userWithRole('finance_manager'));

    expect(SubscriptionPlanResource::canViewAny())->toBeTrue();
    expect(SubscriptionPlanResource::canCreate())->toBeTrue();
    expect(SubscriptionResource::canViewAny())->toBeTrue();
});

it('denies support_agent management of subscription plans', function () {
    $this->actingAs(userWithRole('support_agent'));

    expect(SubscriptionPlanResource::canViewAny())->toBeFalse();
    expect(SubscriptionPlanResource::canCreate())->toBeFalse();
    expect(SubscriptionResource::canViewAny())->toBeFalse();
});

it('denies students management of subscription plans', function () {
    $this->actingAs(userWithRole('student'));

    expect(SubscriptionPlanResource::canViewAny())->toBeFalse();
    expect(SubscriptionPlanResource::canCreate())->toBeFalse();
});

it('the subscription resource is strictly read-only (no create/edit/delete)', function () {
    $this->actingAs(userWithRole('finance_manager'));
    $plan = SubscriptionPlan::create(['name' => 'Pro', 'interval' => 'monthly', 'trial_days' => 0, 'is_active' => true]);
    $subscription = Subscription::create([
        'user_id' => User::factory()->create()->id,
        'plan_id' => $plan->getKey(),
        'status' => 'active',
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
        'currency' => 'SAR',
        'amount_minor' => 9900,
    ]);

    expect(SubscriptionResource::canCreate())->toBeFalse();
    expect(SubscriptionResource::canEdit($subscription))->toBeFalse();
    expect(SubscriptionResource::canDelete($subscription))->toBeFalse();
});

it('persists bilingual plan content and syncs the legacy name scalar', function () {
    $plan = SubscriptionPlan::create([
        'name_i18n' => ['en' => 'Pro', 'ar' => 'محترف'],
        'description_i18n' => ['en' => 'All features', 'ar' => 'كل المزايا'],
        'interval' => 'monthly',
        'trial_days' => 14,
        'is_active' => true,
    ]);

    $fresh = $plan->fresh();

    // jsonb does not preserve key order; assert value equality, not identity.
    expect($fresh->name_i18n)->toEqual(['en' => 'Pro', 'ar' => 'محترف']);
    expect($fresh->description_i18n)->toEqual(['en' => 'All features', 'ar' => 'كل المزايا']);
    // HasTranslations keeps the NOT NULL legacy scalar synced from the default-locale value.
    expect($fresh->name)->toBe('Pro');
    expect($fresh->getAttribute('name_i18n')['ar'])->toBe('محترف');
});
