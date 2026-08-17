<?php

declare(strict_types=1);

use App\Contexts\Commerce\Actions\Payment\FulfillOrderAction;
use App\Contexts\Commerce\Enums\BuyerType;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\OrderItem;
use App\Contexts\Commerce\Models\Product;
use App\Domains\Catalog\Models\Course;
use App\Domains\Crm\Models\Organization;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Analytics\AnalyticsEventName;
use App\Platform\Shared\Analytics\Contracts\AnalyticsEventRecorder;
use App\Platform\Shared\Analytics\Data\AnalyticsEventInput;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Commerce/CommerceHelpers.php';

beforeEach(fn () => app(TenantContext::class)->forget());
afterEach(fn () => app(TenantContext::class)->forget());

function commandCentreAdmin(): User
{
    SpatieRole::findOrCreate('admin', 'web');
    $user = User::factory()->create();
    $user->assignRole('admin');

    return $user;
}

function commandCentrePaidOrder(Product $product, User $buyer, ?Organization $org = null, int $amount = 19900): Order
{
    $order = Order::create([
        'user_id' => $buyer->id,
        'status' => OrderStatus::Paid->value,
        'currency' => 'SAR',
        'subtotal_minor' => $amount, 'discount_minor' => 0, 'tax_minor' => 1500, 'total_minor' => $amount,
        'placed_at' => now(), 'paid_at' => now(),
        'buyer_type' => $org === null ? BuyerType::Individual->value : BuyerType::Company->value,
        'organization_id' => $org?->id,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'title' => $product->title,
        'unit_amount_minor' => $amount,
    ]);

    app(FulfillOrderAction::class)->execute($order);

    return $order->refresh();
}

// ── Executive summary ────────────────────────────────────────────────────────────────────────────

it('reports real revenue split by who bought', function (): void {
    [, $product] = courseProduct(19900);
    $product->forceFill(['audience' => 'both'])->save();

    commandCentrePaidOrder($product, User::factory()->create());
    $org = Organization::factory()->create(['name' => 'Northwind']);
    $companyBuyer = User::factory()->create(['organization_id' => $org->id]);
    commandCentrePaidOrder($product, $companyBuyer, $org, 49900);

    Sanctum::actingAs(commandCentreAdmin());

    $response = $this->getJson('/api/v1/reports/insights/admin-summary')->assertOk();

    $summary = $response->json('data.summary');
    expect($summary['gross_revenue_minor'])->toBe(69800)
        ->and($summary['orders'])->toBe(2)
        ->and($summary['average_order_value_minor'])->toBe(34900)
        ->and($summary['net_revenue_minor'])->toBe(69800);

    $byBuyer = collect($response->json('data.revenue_by_buyer_type'))->keyBy('buyer_type');
    expect((int) $byBuyer['individual']['revenue_minor'])->toBe(19900)
        ->and((int) $byBuyer['company']['revenue_minor'])->toBe(49900);

    expect($response->json('data.top_companies.0.company'))->toBe('Northwind');
});

it('reports seat and certificate figures a director would ask for', function (): void {
    Sanctum::actingAs(commandCentreAdmin());

    $summary = $this->getJson('/api/v1/reports/insights/admin-summary')->assertOk()->json('data.summary');

    // Present and honest on an empty platform: nothing sold means nothing to report, not a blank.
    expect($summary)->toHaveKeys([
        'seats_purchased', 'seats_used', 'entitlements_expiring_30d',
        'certificates_issued', 'questions_asked', 'qna_response_rate',
    ])->and($summary['seats_purchased'])->toBe(0);
});

it('denies the executive summary to a learner', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/reports/insights/admin-summary')->assertForbidden();
});

// ── Marketing funnel ─────────────────────────────────────────────────────────────────────────────

it('builds the funnel from the event log', function (): void {
    $recorder = app(AnalyticsEventRecorder::class);
    $course = Course::factory()->published()->create();

    foreach (range(1, 10) as $i) {
        $recorder->record(new AnalyticsEventInput(
            name: AnalyticsEventName::CourseViewed->value,
            courseId: (int) $course->id,
            sessionId: 's'.$i,
            utmSource: 'newsletter',
        ));
    }
    foreach (range(1, 4) as $i) {
        $recorder->record(new AnalyticsEventInput(name: AnalyticsEventName::CartItemAdded->value, dedupKey: 'c'.$i));
    }
    foreach (range(1, 2) as $i) {
        $recorder->record(new AnalyticsEventInput(name: AnalyticsEventName::CheckoutStarted->value, dedupKey: 'cs'.$i));
    }
    $recorder->record(new AnalyticsEventInput(name: AnalyticsEventName::OrderPaid->value, dedupKey: 'op1'));

    Sanctum::actingAs(commandCentreAdmin());

    $data = $this->getJson('/api/v1/reports/insights/marketing-funnel')->assertOk()->json('data');

    expect($data['summary']['course_views'])->toBe(10)
        ->and($data['summary']['add_to_cart'])->toBe(4)
        ->and($data['summary']['checkout_started'])->toBe(2)
        ->and($data['summary']['orders_paid'])->toBe(1)
        // 4 of 10 viewers added to cart.
        ->and((float) $data['summary']['add_to_cart_rate'])->toBe(40.0)
        ->and($data['most_viewed_courses'][0]['views'])->toBe(10)
        ->and($data['traffic_sources'][0]['source'])->toBe('newsletter');
});

it('says when nothing has been tracked rather than reporting a zero that reads like a finding', function (): void {
    Sanctum::actingAs(commandCentreAdmin());

    $data = $this->getJson('/api/v1/reports/insights/marketing-funnel')->assertOk()->json('data');

    // No events at all: the payload states that tracking has recorded nothing, so a reader cannot
    // mistake an unmeasured stage for a measured zero.
    expect($data['tracking_since'])->toBeNull()
        ->and($data['summary']['course_views'])->toBe(0);
});

it('counts search terms people actually typed', function (): void {
    $recorder = app(AnalyticsEventRecorder::class);
    foreach (['project management', 'project management', 'agile'] as $i => $term) {
        $recorder->record(new AnalyticsEventInput(
            name: AnalyticsEventName::SearchPerformed->value,
            metadata: ['term' => $term],
            dedupKey: 'search'.$i,
        ));
    }

    Sanctum::actingAs(commandCentreAdmin());

    $terms = collect($this->getJson('/api/v1/reports/insights/marketing-funnel')->assertOk()->json('data.search_terms'));

    expect($terms->firstWhere('term', 'project management')['searches'])->toBe(2);
});

// ── Accounting ───────────────────────────────────────────────────────────────────────────────────

it('reports invoices, tax and buyer split for finance', function (): void {
    [, $product] = courseProduct(19900);
    $product->forceFill(['audience' => 'both'])->save();

    commandCentrePaidOrder($product, User::factory()->create());
    $org = Organization::factory()->create();
    commandCentrePaidOrder($product, User::factory()->create(['organization_id' => $org->id]), $org, 49900);

    Sanctum::actingAs(commandCentreAdmin());

    $data = $this->getJson('/api/v1/reports/insights/accounting')->assertOk()->json('data');

    expect($data['summary']['orders'])->toBe(2)
        // Tax is summed from the orders as settled, not recomputed from a rate.
        ->and($data['summary']['tax_collected_minor'])->toBe(3000)
        ->and($data['summary']['refunds_minor'])->toBe(0)
        ->and(collect($data['orders_by_status'])->firstWhere('status', 'paid')['orders'])->toBe(2);
});

it('denies the accounting report to a learner', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/reports/insights/accounting')->assertForbidden();
});

it('lists the new reports in the catalog so they are discoverable', function (): void {
    Sanctum::actingAs(commandCentreAdmin());

    $keys = collect($this->getJson('/api/v1/reports/insights/catalog')->assertOk()->json('data'))->pluck('key');

    expect($keys)->toContain('admin_summary')
        ->and($keys)->toContain('marketing_funnel')
        ->and($keys)->toContain('accounting');
});
