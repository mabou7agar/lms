<?php

use App\Contexts\Analytics\Database\Seeders\AnalyticsSeeder;
use App\Contexts\Commerce\Events\OrderPaid;
use App\Contexts\Commerce\Models\Order;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Events\UserRegistered;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class);
    // AnalyticsSeeder too: analytics access is now gated on the `analytics.view` PERMISSION, so a
    // caller needs the permission rows to exist, not merely the admin role.
    $this->seed(AnalyticsSeeder::class);
});

it('turns producer events into read-model snapshots surfaced as KPIs', function () {
    // Producer events (Analytics only listens to EVENTS, never their tables).
    UserRegistered::dispatch(User::factory()->create());
    UserRegistered::dispatch(User::factory()->create());
    OrderPaid::dispatch(Order::factory()->create(['total_minor' => 15000]));

    // Revenue is administrator-only, so this reader must be one — otherwise the money metric is
    // filtered out of the response and the assertion below would fail for an authorization reason
    // rather than a rollup one.
    $reader = User::factory()->create();
    $reader->assignRole(SpatieRole::findByName('admin', 'web'));
    Sanctum::actingAs($reader);

    $res = $this->getJson('/api/v1/analytics/kpis?metrics[]=signups&metrics[]=revenue')->assertOk();

    $kpis = collect($res->json('data.kpis'))->keyBy('metric');
    expect($kpis['signups']['total'])->toBe(2)
        ->and($kpis['revenue']['total'])->toBe(15000)
        ->and($kpis['revenue']['unit'])->toBe('currency_minor');
});
