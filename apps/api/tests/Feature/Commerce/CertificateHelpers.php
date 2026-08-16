<?php

declare(strict_types=1);

use App\Contexts\Commerce\Actions\Payment\FulfillOrderAction;
use App\Contexts\Commerce\Enums\BuyerType;
use App\Contexts\Commerce\Enums\CompanyCertificateBranding;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\SeatMode;
use App\Contexts\Commerce\Models\CompanyEntitlement;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\OrderItem;
use App\Contexts\Commerce\Models\Product;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Commerce\Contracts\CompanyEntitlementPort;
use App\Platform\Shared\Commerce\Data\SeatCandidate;

require_once __DIR__.'/CommerceHelpers.php';

/*
 | Shared setup for the commercial-certificate and expiry-reminder suites: a product with a
 | certificate policy, a paid order that runs real fulfilment, and a company employee holding a
 | seat. Extracted so both suites can use them without one test file requiring another, which
 | would run the same tests twice.
 */

/** A product selling one course, with the certificate policy under test. */
function certificateProduct(array $policy = []): array
{
    [$course, $product] = courseProduct(19900);

    $product->forceFill(array_merge([
        'audience' => 'both',
        'certificate_enabled' => true,
        'certificate_expiry_type' => 'fixed_years',
        'certificate_expiry_value' => 2,
        'seat_mode' => SeatMode::Fixed->value,
        'default_seat_count' => 5,
        'company_certificate_branding' => CompanyCertificateBranding::CompanyLogoAndHelbaron->value,
    ], $policy))->save();

    return [$product->refresh(), $course];
}

function paidOrderFor(Product $product, User $buyer, ?Organization $org = null): Order
{
    $order = Order::create([
        'user_id' => $buyer->id,
        'status' => OrderStatus::Paid->value,
        'currency' => 'SAR',
        'subtotal_minor' => 19900, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 19900,
        'placed_at' => now(), 'paid_at' => now(),
        'buyer_type' => $org === null ? BuyerType::Individual->value : BuyerType::Company->value,
        'organization_id' => $org?->id,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'title' => $product->title,
        'unit_amount_minor' => 19900,
    ]);

    app(FulfillOrderAction::class)->execute($order);

    return $order->refresh();
}

function certificateCompanyOwner(Organization $org): User
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMember::create([
        'organization_id' => $org->id, 'user_id' => $user->id, 'email' => $user->email,
        'role' => 'owner', 'status' => 'active',
    ]);

    return $user;
}

function seatedEmployee(Organization $org, CompanyEntitlement $entitlement, string $email): User
{
    $user = User::factory()->create();
    $member = OrganizationMember::create([
        'organization_id' => $org->id, 'user_id' => $user->id, 'email' => $email,
        'role' => 'member', 'status' => 'active',
    ]);

    app(CompanyEntitlementPort::class)->assign(
        (int) $org->getKey(),
        (string) $entitlement->public_id,
        [new SeatCandidate((int) $member->getKey(), (int) $user->id)],
    );

    return $user;
}
