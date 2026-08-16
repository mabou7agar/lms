<?php

use App\Contexts\Commerce\Enums\AccessDurationType;
use App\Contexts\Commerce\Enums\CertificateExpiryType;
use App\Contexts\Commerce\Enums\ProductAudience;
use App\Contexts\Commerce\Enums\ProductStatus;
use App\Contexts\Commerce\Enums\ProductType;
use App\Contexts\Commerce\Enums\RefundAccessPolicy;
use App\Contexts\Commerce\Enums\SeatMode;
use App\Contexts\Commerce\Enums\SeatReassignmentPolicy;
use App\Contexts\Commerce\Models\Product;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Commerce\Contracts\PurchaseSummaryPort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** A product carrying an explicit commercial policy, granting the given courses. */
function policyProduct(array $attributes = [], array $courses = []): Product
{
    $product = Product::factory()->create(array_merge([
        'type' => ProductType::Course->value,
        'status' => ProductStatus::Active->value,
        'audience' => ProductAudience::Individual->value,
    ], $attributes));

    if ($courses !== []) {
        $product->courses()->sync(collect($courses)->map(fn (Course $c): int => (int) $c->id)->all());
    }

    return $product->refresh();
}

it('persists every admin-controlled policy field and casts it back as an enum', function (): void {
    $product = policyProduct([
        'audience' => ProductAudience::Both->value,
        'access_duration_type' => AccessDurationType::FixedMonths->value,
        'access_duration_value' => 6,
        'certificate_enabled' => true,
        'certificate_expiry_type' => CertificateExpiryType::FixedYears->value,
        'certificate_expiry_value' => 2,
        'reminder_offsets_days' => [30, 7, 1],
        'reminder_channels' => ['email', 'in_app'],
        'refund_access_policy' => RefundAccessPolicy::KeepUntilPeriodEnd->value,
        'seat_mode' => SeatMode::Fixed->value,
        'default_seat_count' => 25,
        'seat_reassignment_policy' => SeatReassignmentPolicy::BeforeProgressThreshold->value,
        'reassignment_progress_threshold' => 20,
        'employee_access_expires_with_purchase' => true,
    ]);

    expect($product->audience)->toBe(ProductAudience::Both)
        ->and($product->access_duration_type)->toBe(AccessDurationType::FixedMonths)
        ->and($product->access_duration_value)->toBe(6)
        ->and($product->certificate_expiry_type)->toBe(CertificateExpiryType::FixedYears)
        ->and($product->refund_access_policy)->toBe(RefundAccessPolicy::KeepUntilPeriodEnd)
        ->and($product->seat_mode)->toBe(SeatMode::Fixed)
        ->and($product->default_seat_count)->toBe(25)
        ->and($product->seat_reassignment_policy)->toBe(SeatReassignmentPolicy::BeforeProgressThreshold)
        ->and($product->reassignment_progress_threshold)->toBe(20)
        ->and($product->employee_access_expires_with_purchase)->toBeTrue()
        // Reminder offsets come back largest-first so the earliest notice is sent first.
        ->and($product->reminderOffsets())->toBe([30, 7, 1])
        ->and($product->reminderChannels())->toBe(['email', 'in_app']);
});

it('defaults a new product to the pre-existing behaviour: lifetime access with a certificate', function (): void {
    $product = policyProduct();

    expect($product->access_duration_type)->toBe(AccessDurationType::Lifetime)
        ->and($product->certificate_enabled)->toBeTrue()
        ->and($product->certificate_expiry_type)->toBe(CertificateExpiryType::None)
        ->and($product->audience)->toBe(ProductAudience::Individual)
        ->and($product->seat_mode)->toBe(SeatMode::NotApplicable);
});

it('resolves access and certificate expiry from the configured policy', function (): void {
    $bought = Carbon::parse('2026-01-10 12:00:00');

    $lifetime = policyProduct(['access_duration_type' => AccessDurationType::Lifetime->value]);
    expect($lifetime->accessEndsAfter($bought))->toBeNull();

    $months = policyProduct([
        'access_duration_type' => AccessDurationType::FixedMonths->value,
        'access_duration_value' => 3,
    ]);
    expect($months->accessEndsAfter($bought)->toDateString())->toBe('2026-04-10');

    $dated = policyProduct([
        'access_duration_type' => AccessDurationType::FixedDate->value,
        'access_ends_at' => Carbon::parse('2026-06-30 23:59:59'),
    ]);
    expect($dated->accessEndsAfter($bought)->toDateString())->toBe('2026-06-30');

    $cert = policyProduct([
        'certificate_enabled' => true,
        'certificate_expiry_type' => CertificateExpiryType::FixedYears->value,
        'certificate_expiry_value' => 2,
    ]);
    expect($cert->certificateExpiresAfter($bought)->toDateString())->toBe('2028-01-10');

    // No certificate issued means nothing to expire, whatever the expiry setting says.
    $noCert = policyProduct([
        'certificate_enabled' => false,
        'certificate_expiry_type' => CertificateExpiryType::FixedYears->value,
        'certificate_expiry_value' => 2,
    ]);
    expect($noCert->certificateExpiresAfter($bought))->toBeNull();
});

it('builds a bundle that grants several courses', function (): void {
    $courses = Course::factory()->count(3)->create();
    $bundle = policyProduct([
        'type' => ProductType::Bundle->value,
        'audience' => ProductAudience::Company->value,
        'seat_mode' => SeatMode::Fixed->value,
        'default_seat_count' => 10,
    ], $courses->all());

    expect($bundle->type)->toBe(ProductType::Bundle)
        ->and($bundle->courses)->toHaveCount(3)
        ->and($bundle->courseIds())->toHaveCount(3)
        ->and($bundle->audience->allowsCompany())->toBeTrue()
        ->and($bundle->audience->allowsIndividual())->toBeFalse();
});

it('reports a purchase summary for a sold course, quoting the single-course product over a bundle', function (): void {
    $course = Course::factory()->create();

    $single = policyProduct([
        'type' => ProductType::Course->value,
        'access_duration_type' => AccessDurationType::FixedDays->value,
        'access_duration_value' => 90,
    ], [$course]);
    $single->prices()->create([
        'currency' => 'SAR', 'amount_minor' => 49900, 'is_default' => true,
    ]);

    $bundle = policyProduct(['type' => ProductType::Bundle->value], [$course]);

    $summary = app(PurchaseSummaryPort::class)->forCourse((int) $course->id);

    expect($summary->purchasable)->toBeTrue()
        ->and($summary->productId)->toBe($single->public_id)
        ->and($summary->productType)->toBe('course')
        ->and($summary->currency)->toBe('SAR')
        ->and($summary->effectiveMinor)->toBe(49900)
        ->and($summary->accessDurationType)->toBe('fixed_days')
        ->and($summary->accessDurationValue)->toBe(90)
        // The bundle that also grants it is offered as a cross-sell, not as the headline price.
        ->and($summary->includedInBundleIds)->toBe([$bundle->public_id]);
});

it('reports a sale price as the effective price while the sale window is open', function (): void {
    $course = Course::factory()->create();
    $product = policyProduct([], [$course]);
    $product->prices()->create([
        'currency' => 'SAR',
        'amount_minor' => 40000,
        'sale_amount_minor' => 25000,
        'sale_starts_at' => now()->subDay(),
        'sale_ends_at' => now()->addDay(),
        'is_default' => true,
    ]);

    $summary = app(PurchaseSummaryPort::class)->forCourse((int) $course->id);

    expect($summary->onSale)->toBeTrue()
        ->and($summary->amountMinor)->toBe(40000)
        ->and($summary->effectiveMinor)->toBe(25000);
});

it('does not treat a course as purchasable when only a draft product grants it', function (): void {
    $course = Course::factory()->create();
    policyProduct(['status' => ProductStatus::Draft->value], [$course]);

    $summary = app(PurchaseSummaryPort::class)->forCourse((int) $course->id);

    expect($summary->purchasable)->toBeFalse()
        ->and($summary->toArray())->toBe(['purchasable' => false]);
});

it('answers a summary for every requested course in one batched call', function (): void {
    $sold = Course::factory()->create();
    $unsold = Course::factory()->create();
    policyProduct([], [$sold]);

    $summaries = app(PurchaseSummaryPort::class)->forCourseIds([(int) $sold->id, (int) $unsold->id]);

    expect($summaries)->toHaveCount(2)
        ->and($summaries[(int) $sold->id]->purchasable)->toBeTrue()
        ->and($summaries[(int) $unsold->id]->purchasable)->toBeFalse();
});
