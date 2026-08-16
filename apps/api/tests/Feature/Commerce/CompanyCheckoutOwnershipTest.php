<?php

use App\Contexts\Commerce\Actions\Cart\SetCartBuyerAction;
use App\Contexts\Commerce\Actions\Checkout\CheckoutAction;
use App\Contexts\Commerce\Enums\BuyerType;
use App\Contexts\Commerce\Enums\ProductAudience;
use App\Contexts\Commerce\Enums\ProductStatus;
use App\Contexts\Commerce\Exceptions\BuyerAudienceMismatchException;
use App\Contexts\Commerce\Exceptions\CompanyBuyerRequiredException;
use App\Contexts\Commerce\Models\Product;
use App\Contexts\Commerce\Services\CartService;
use App\Domains\Crm\Enums\MemberRole;
use App\Domains\Crm\Enums\MemberStatus;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function pricedProduct(ProductAudience $audience): Product
{
    $product = Product::factory()->create([
        'status' => ProductStatus::Active->value,
        'audience' => $audience->value,
    ]);
    $product->prices()->create([
        'currency' => (string) config('commerce.default_currency'),
        'amount_minor' => 50000,
        'is_default' => true,
    ]);

    return $product;
}

function ownerOfOrganization(array $organizationAttributes = []): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create($organizationAttributes);
    OrganizationMember::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'email' => $user->email,
        'role' => MemberRole::Owner->value,
        'status' => MemberStatus::Active->value,
        'joined_at' => now(),
    ]);

    return [$user, $organization];
}

it('records an individual purchase as owned by the buyer, with no company', function (): void {
    $user = User::factory()->create();
    $carts = app(CartService::class);
    $carts->addProduct($carts->currentByUserId((int) $user->id), pricedProduct(ProductAudience::Individual));

    $result = app(CheckoutAction::class)->executeByUserId((int) $user->id);
    $order = $result['order'];

    expect($order->buyer_type)->toBe(BuyerType::Individual)
        ->and($order->organization_id)->toBeNull()
        ->and($order->company_name)->toBeNull();
});

it('records a company purchase against the organization and bills the company profile', function (): void {
    [$user, $organization] = ownerOfOrganization([
        'name' => 'Acme Trading',
        'country' => 'SA',
        'tax_id' => '300000000000003',
        'billing_address' => 'Riyadh, KSA',
    ]);

    app(SetCartBuyerAction::class)->executeByUserId((int) $user->id, BuyerType::Company);
    $carts = app(CartService::class);
    $carts->addProduct($carts->currentByUserId((int) $user->id), pricedProduct(ProductAudience::Company));

    $result = app(CheckoutAction::class)->executeByUserId((int) $user->id);
    $order = $result['order'];

    expect($order->buyer_type)->toBe(BuyerType::Company)
        ->and((int) $order->organization_id)->toBe((int) $organization->id)
        // The invoice carries the company's legal identity, not the buyer's personal details.
        ->and($order->company_name)->toBe('Acme Trading')
        ->and($order->billing_name)->toBe('Acme Trading')
        ->and($order->billing_country)->toBe('SA')
        ->and($order->billing_tax_id)->toBe('300000000000003')
        ->and($order->billing_address)->toBe('Riyadh, KSA');
});

it('lets the buyer override the billing details confirmed at checkout', function (): void {
    [$user] = ownerOfOrganization(['name' => 'Acme Trading', 'country' => 'SA']);

    app(SetCartBuyerAction::class)->executeByUserId((int) $user->id, BuyerType::Company);
    $carts = app(CartService::class);
    $carts->addProduct($carts->currentByUserId((int) $user->id), pricedProduct(ProductAudience::Both));

    $result = app(CheckoutAction::class)->executeByUserId((int) $user->id, [
        'billing_email' => 'ap@acme.test',
        'billing_country' => 'AE',
    ]);

    expect($result['order']->billing_email)->toBe('ap@acme.test')
        ->and($result['order']->billing_country)->toBe('AE');
});

// A company cart whose organization vanished (or was never resolved) would produce an order nobody
// can be invoiced for and no one can receive seats from.
it('refuses to check out a company cart with no organization', function (): void {
    $user = User::factory()->create();
    $carts = app(CartService::class);
    $cart = $carts->currentByUserId((int) $user->id);
    $carts->addProduct($cart, pricedProduct(ProductAudience::Both));
    $cart->forceFill(['buyer_type' => BuyerType::Company->value, 'organization_id' => null])->save();

    app(CheckoutAction::class)->executeByUserId((int) $user->id);
})->throws(CompanyBuyerRequiredException::class);

// The audience is re-checked at checkout because an admin can change it while a cart sits idle.
it('refuses to check out a cart holding something the buyer may no longer buy', function (): void {
    $user = User::factory()->create();
    $carts = app(CartService::class);
    $product = pricedProduct(ProductAudience::Both);
    $carts->addProduct($carts->currentByUserId((int) $user->id), $product);

    $product->forceFill(['audience' => ProductAudience::Company->value])->save();

    app(CheckoutAction::class)->executeByUserId((int) $user->id);
})->throws(BuyerAudienceMismatchException::class);
