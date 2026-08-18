<?php

declare(strict_types=1);

use App\Contexts\Commerce\Database\Seeders\CommerceSeeder;
use App\Contexts\Commerce\Models\Product;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Shared\Commerce\Contracts\PurchaseSummaryPort;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * There are no free courses. A published course that cannot be bought is a defect, not a state.
 *
 * The seeder used to stop after three courses, which was invisible while the catalogue had three and
 * became a 75%-broken shop once it had twelve: nine public cards read "Not available yet" and their
 * detail pages offered nothing to buy.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('gives every published course a purchasable product with a price', function (): void {
    Course::factory()->published()->count(7)->create();
    Course::factory()->create(['status' => 'draft']);

    $this->seed(CommerceSeeder::class);

    $summaries = app(PurchaseSummaryPort::class)->forCourseIds(
        Course::query()->where('status', 'published')->pluck('id')->all(),
    );

    expect($summaries)->toHaveCount(7);
    foreach ($summaries as $courseId => $summary) {
        expect($summary->purchasable)->toBeTrue("course {$courseId} is not purchasable")
            ->and($summary->effectiveMinor)->toBeGreaterThan(0, "course {$courseId} has no price");
    }
});

it('leaves a draft course alone', function (): void {
    $draft = Course::factory()->create(['status' => 'draft']);

    $this->seed(CommerceSeeder::class);

    expect(app(PurchaseSummaryPort::class)->forCourse($draft->id)->purchasable)->toBeFalse();
});

it('is idempotent: a second run adds no product and rewrites no price', function (): void {
    Course::factory()->published()->count(4)->create();

    $this->seed(CommerceSeeder::class);
    $product = Product::query()->where('type', 'course')->firstOrFail();
    $product->prices()->update(['amount_minor' => 44400]);
    $before = Product::count();

    $this->seed(CommerceSeeder::class);

    // An admin who repriced a course keeps that price when the seeder runs again.
    expect(Product::count())->toBe($before)
        ->and((int) $product->prices()->value('amount_minor'))->toBe(44400);
});

it('quotes the same product however many sell the course', function (): void {
    // Two active course-products for one course is a data fault, but the answer must not depend on
    // row order: the card, the detail page and the cart have to agree on a single price.
    $course = Course::factory()->published()->create();
    $this->seed(CommerceSeeder::class);

    $duplicate = Product::factory()->create(['type' => 'course', 'status' => 'active']);
    $duplicate->courses()->attach($course->id);
    $duplicate->prices()->create(['currency' => 'SAR', 'amount_minor' => 999900, 'is_default' => true]);

    $port = app(PurchaseSummaryPort::class);
    $first = $port->forCourse($course->id);

    for ($i = 0; $i < 5; $i++) {
        expect($port->forCourse($course->id)->productId)->toBe($first->productId);
    }
    // Oldest wins — the one already advertised, not the newcomer.
    expect($first->effectiveMinor)->not->toBe(999900);
});
