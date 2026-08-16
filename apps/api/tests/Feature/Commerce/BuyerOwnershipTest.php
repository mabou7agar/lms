<?php

use App\Contexts\Commerce\Actions\Cart\SetCartBuyerAction;
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
use App\Platform\Identity\Actions\Auth\RegisterUserAction;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Enterprise\Contracts\OrgManagerCheckPort;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Registration assigns the student role, so the role table has to exist.
    $this->seed(RolePermissionSeeder::class);
});

/** An active product sold to the given audience, priced in the cart's default currency. */
function audienceProduct(ProductAudience $audience): Product
{
    $product = Product::factory()->create([
        'status' => ProductStatus::Active->value,
        'audience' => $audience->value,
    ]);

    $product->prices()->create([
        'currency' => (string) config('commerce.default_currency'),
        'amount_minor' => 19900,
        'is_default' => true,
    ]);

    return $product;
}

/** A user who owns an organization, i.e. may buy on its behalf. */
function companyOwner(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    OrganizationMember::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'email' => $user->email,
        'role' => MemberRole::Owner->value,
        'status' => MemberStatus::Active->value,
        'joined_at' => now(),
    ]);
    $user->forceFill(['organization_id' => $organization->id])->save();

    return [$user, $organization];
}

describe('registration', function (): void {
    it('registers a personal account without creating an organization', function (): void {
        $user = app(RegisterUserAction::class)->execute([
            'name' => 'Sara Individual',
            'email' => 'sara@example.test',
            'password' => 'password123',
        ]);

        expect($user->exists)->toBeTrue()
            ->and($user->organization_id)->toBeNull()
            ->and(Organization::count())->toBe(0);
    });

    it('registers a company account, creating the organization and making the user its owner', function (): void {
        $user = app(RegisterUserAction::class)->execute([
            'name' => 'Omar Admin',
            'email' => 'omar@acme.test',
            'password' => 'password123',
            'account_type' => 'company',
            'company' => [
                'name' => 'Acme Trading',
                'size' => '51-200',
                'country' => 'SA',
                'tax_id' => '300000000000003',
            ],
        ]);

        $organization = Organization::where('name', 'Acme Trading')->first();

        expect($organization)->not->toBeNull()
            ->and($organization->getAttribute('country'))->toBe('SA')
            ->and($organization->getAttribute('tax_id'))->toBe('300000000000003')
            ->and((int) $user->organization_id)->toBe((int) $organization->id);

        $membership = OrganizationMember::where('user_id', $user->id)->first();
        expect($membership->role)->toBe(MemberRole::Owner)
            ->and($membership->status)->toBe(MemberStatus::Active);
    });

    // Manager authority is data-driven: owning the organization is what grants it, with no Spatie
    // role involved. Registering a company must therefore produce a working manager immediately.
    it('makes the registering company user a manager without assigning any role', function (): void {
        $user = app(RegisterUserAction::class)->execute([
            'name' => 'Owner',
            'email' => 'owner@acme.test',
            'password' => 'password123',
            'account_type' => 'company',
            'company' => ['name' => 'Acme Two'],
        ]);

        expect(app(OrgManagerCheckPort::class)->managesAnyOrganization((int) $user->id))->toBeTrue()
            ->and($user->getRoleNames()->all())->not->toContain('org_manager');
    });
});

describe('buyer audience rules', function (): void {
    it('lets an individual buy an individual-only and a both-audience product', function (): void {
        $user = User::factory()->create();
        $carts = app(CartService::class);
        $cart = $carts->currentByUserId((int) $user->id);

        $carts->addProduct($cart, audienceProduct(ProductAudience::Individual));
        $carts->addProduct($cart, audienceProduct(ProductAudience::Both));

        expect($cart->items()->count())->toBe(2);
    });

    it('refuses to let an individual buy a company-only product', function (): void {
        $user = User::factory()->create();
        $carts = app(CartService::class);
        $cart = $carts->currentByUserId((int) $user->id);

        $carts->addProduct($cart, audienceProduct(ProductAudience::Company));
    })->throws(BuyerAudienceMismatchException::class);

    it('lets a company buy a company-only and a both-audience product', function (): void {
        [$user] = companyOwner();
        $carts = app(CartService::class);
        app(SetCartBuyerAction::class)->executeByUserId((int) $user->id, BuyerType::Company);
        $cart = $carts->currentByUserId((int) $user->id);

        $carts->addProduct($cart, audienceProduct(ProductAudience::Company));
        $carts->addProduct($cart, audienceProduct(ProductAudience::Both));

        expect($cart->items()->count())->toBe(2);
    });

    it('refuses to let a company buy an individual-only product', function (): void {
        [$user] = companyOwner();
        app(SetCartBuyerAction::class)->executeByUserId((int) $user->id, BuyerType::Company);
        $cart = app(CartService::class)->currentByUserId((int) $user->id);

        app(CartService::class)->addProduct($cart, audienceProduct(ProductAudience::Individual));
    })->throws(BuyerAudienceMismatchException::class);

    // Products created before the audience existed were backfilled to `individual` by the migration
    // default, so a catalogue that predates buyer ownership stays purchasable exactly as before.
    it('defaults a product with no audience specified to individuals', function (): void {
        $user = User::factory()->create();
        $carts = app(CartService::class);
        $cart = $carts->currentByUserId((int) $user->id);

        $product = Product::factory()->create(['status' => ProductStatus::Active->value]);
        $product->prices()->create([
            'currency' => (string) config('commerce.default_currency'),
            'amount_minor' => 19900,
            'is_default' => true,
        ]);

        expect($product->refresh()->audience)->toBe(ProductAudience::Individual);

        $carts->addProduct($cart, $product);
        expect($cart->items()->count())->toBe(1);
    });
});

describe('switching buyer mode', function (): void {
    it('resolves the organization from the caller rather than the request', function (): void {
        [$user, $organization] = companyOwner();

        $cart = app(SetCartBuyerAction::class)->executeByUserId((int) $user->id, BuyerType::Company);

        expect($cart->buyerType())->toBe(BuyerType::Company)
            ->and((int) $cart->organization_id)->toBe((int) $organization->id);
    });

    it('refuses a company purchase for a user who manages no organization', function (): void {
        $user = User::factory()->create();

        app(SetCartBuyerAction::class)->executeByUserId((int) $user->id, BuyerType::Company);
    })->throws(CompanyBuyerRequiredException::class);

    it('refuses a switch that would strand an item already in the cart', function (): void {
        [$user] = companyOwner();
        $carts = app(CartService::class);
        $cart = $carts->currentByUserId((int) $user->id);
        $carts->addProduct($cart, audienceProduct(ProductAudience::Individual));

        app(SetCartBuyerAction::class)->executeByUserId((int) $user->id, BuyerType::Company);
    })->throws(BuyerAudienceMismatchException::class);
});
