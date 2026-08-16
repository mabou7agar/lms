<?php

use App\Contexts\Commerce\Enums\BuyerType;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
require_once __DIR__.'/CommerceHelpers.php';

// Browser smoke found this: the route pointed at a controller method that does not exist, so every
// "empty cart" request was a 500 rather than clearing anything.
it('clears the cart through the route the UI actually calls', function () {
    [, $product] = courseProduct(19900);
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/cart', ['product' => $product->public_id])->assertOk();
    $this->getJson('/api/v1/cart')->assertOk()->assertJsonCount(1, 'data.items');

    $this->deleteJson('/api/v1/cart')->assertOk();

    $this->getJson('/api/v1/cart')->assertOk()->assertJsonCount(0, 'data.items');
});

it('exposes the buyer on the cart so the UI can show who is purchasing', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/cart')
        ->assertOk()
        ->assertJsonPath('data.buyer_type', BuyerType::Individual->value)
        ->assertJsonPath('data.organization_id', null);
});

// The post-checkout page reads the orders LIST to decide where to send the buyer. Without buyer
// ownership on that payload a company purchase silently routed to My Learning instead of the
// training portal, so the field has to be present on the list resource, not only on the order one.
it('exposes buyer ownership on the orders list', function () {
    [, $product] = courseProduct(19900);
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/cart', ['product' => $product->public_id])->assertOk();
    $this->postJson('/api/v1/checkout')->assertCreated();

    $this->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonPath('data.0.buyer_type', BuyerType::Individual->value)
        ->assertJsonPath('data.0.company_name', null)
        ->assertJsonStructure(['data' => [['buyer_type', 'company_name', 'billing' => ['name', 'email', 'country', 'tax_id']]]]);
});
