<?php

use App\Contexts\Analytics\Enums\InsightReport;
use App\Contexts\Commerce\Models\Coupon;
use App\Contexts\Commerce\Models\CouponRedemption;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\OrderItem;
use App\Contexts\Commerce\Models\PaymentTransaction;
use App\Contexts\Commerce\Models\Product;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LessonProgress;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Catalog\Models\Course;
use App\Domains\Certification\Models\Certificate;
use App\Domains\Crm\Models\ConsultingRequest;
use App\Domains\Crm\Models\Lead;
use App\Domains\Crm\Models\Opportunity;
use App\Domains\Live\Models\LiveSession;
use App\Domains\Live\Models\SessionAttendance;
use App\Domains\Live\Models\SessionRegistration;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Enums\Role;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

/** An admin user (web-guard role assigned explicitly, robust to Sanctum's guard switch). */
function reportAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findByName(Role::Admin->value, 'web'));

    return $user;
}

/** The 11 operational report endpoints (path segment under reports/insights/). */
function insightPaths(): array
{
    return [
        'revenue', 'commerce', 'course-performance', 'instructor-performance',
        'organization-performance', 'certificates', 'live-sessions', 'learner-activity',
        'completion-funnel', 'retention', 'crm',
    ];
}

/**
 * Seed a small, fully-real fixture that exercises every report and pins a few known aggregates:
 * one paid order of 30000 minor with a 5000 minor refund (net 25000), one completed+certified
 * enrollment, one attended live session, one won opportunity worth 10000 minor.
 */
function seedReportFixture(): void
{
    $student = User::factory()->create();
    $course = Course::factory()->published()->create();

    // Commerce: paid order -> item -> product mapped to the course; plus a succeeded refund.
    $product = Product::factory()->create(['title' => 'Flagship Course']);
    DB::table('product_courses')->insert(['product_id' => $product->id, 'course_id' => $course->id]);

    $order = Order::factory()->paid()->create([
        'user_id' => $student->id,
        'subtotal_minor' => 30000,
        'discount_minor' => 0,
        'total_minor' => 30000,
    ]);
    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'title' => 'Flagship Course',
        'unit_amount_minor' => 30000,
    ]);
    PaymentTransaction::create([
        'order_id' => $order->id,
        'provider' => 'stripe',
        'type' => 'refund',
        'status' => 'succeeded',
        'amount_minor' => 5000,
        'currency' => 'SAR',
    ]);

    $coupon = Coupon::factory()->create();
    CouponRedemption::create(['coupon_id' => $coupon->id, 'user_id' => $student->id, 'order_id' => $order->id]);

    // Learning: a completed enrollment with a completed lesson (drives funnel + activity).
    $enrollment = Enrollment::factory()->completed()->create([
        'user_id' => $student->id,
        'course_id' => $course->id,
    ]);
    $lesson = Lesson::factory()->create();
    LessonProgress::create([
        'enrollment_id' => $enrollment->id,
        'lesson_id' => $lesson->id,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    // Certification: an issued certificate for the same (user, course) -> funnel "certified".
    Certificate::factory()->create(['user_id' => $student->id, 'course_id' => $course->id]);

    // Live: a completed session with one registration and one attendance (attendance_rate 100).
    $session = LiveSession::factory()->completed()->create();
    SessionRegistration::create([
        'session_id' => $session->id,
        'user_id' => $student->id,
        'status' => 'registered',
        'registered_at' => now(),
    ]);
    SessionAttendance::create([
        'session_id' => $session->id,
        'user_id' => $student->id,
        'source' => 'self_join',
        'joined_at' => now(),
    ]);

    // CRM: a lead, a won opportunity worth 10000 minor, and a consulting request.
    Lead::factory()->create();
    Opportunity::create(['name' => 'Won Deal', 'status' => 'won', 'amount_minor' => 10000, 'currency' => 'SAR']);
    ConsultingRequest::factory()->create();
}

it('exposes the report catalog with every report', function () {
    Sanctum::actingAs(reportAdmin());

    $res = $this->getJson('/api/v1/reports/insights/catalog')->assertOk();

    // The count is asserted against the enum rather than a literal so adding a report cannot leave
    // the catalog and the dispatcher out of step without this failing.
    expect($res->json('data'))->toHaveCount(count(InsightReport::cases()));
    expect(collect($res->json('data'))->pluck('key'))
        ->toContain('revenue', 'completion_funnel', 'retention', 'crm', 'admin_summary', 'marketing_funnel', 'accounting');
});

it('returns every report to an admin with a real payload', function () {
    seedReportFixture();
    Sanctum::actingAs(reportAdmin());

    foreach (insightPaths() as $path) {
        $this->getJson("/api/v1/reports/insights/{$path}")
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['from', 'to']]);
    }
});

it('computes net revenue from a real paid order minus a real refund', function () {
    seedReportFixture();
    Sanctum::actingAs(reportAdmin());

    $res = $this->getJson('/api/v1/reports/insights/revenue')->assertOk();

    expect($res->json('data.summary.gross_minor'))->toBe(30000)
        ->and($res->json('data.summary.refunds_minor'))->toBe(5000)
        ->and($res->json('data.summary.net_minor'))->toBe(25000)
        ->and($res->json('data.summary.orders'))->toBe(1);

    // Per-course revenue is allocated through order_items -> product_courses (one course here).
    expect(collect($res->json('data.by_course'))->first()['revenue_minor'])->toBe(30000);
});

it('counts a completed and certified enrollment in the completion funnel', function () {
    seedReportFixture();
    Sanctum::actingAs(reportAdmin());

    $steps = collect($this->getJson('/api/v1/reports/insights/completion-funnel')->assertOk()->json('data.steps'))
        ->keyBy('step');

    expect($steps['enrolled']['count'])->toBe(1)
        ->and($steps['started']['count'])->toBe(1)
        ->and($steps['completed']['count'])->toBe(1)
        ->and($steps['certified']['count'])->toBe(1);
});

it('counts an issued certificate', function () {
    seedReportFixture();
    Sanctum::actingAs(reportAdmin());

    expect($this->getJson('/api/v1/reports/insights/certificates')->assertOk()->json('data.summary.issued'))->toBe(1);
});

it('computes live-session attendance from real registrations and attendances', function () {
    seedReportFixture();
    Sanctum::actingAs(reportAdmin());

    $summary = $this->getJson('/api/v1/reports/insights/live-sessions')->assertOk()->json('data.summary');

    expect($summary['registrations'])->toBe(1)
        ->and($summary['attendances'])->toBe(1)
        ->and((float) $summary['attendance_rate'])->toBe(100.0);
});

it('reports a won CRM opportunity with its value', function () {
    seedReportFixture();
    Sanctum::actingAs(reportAdmin());

    $summary = $this->getJson('/api/v1/reports/insights/crm')->assertOk()->json('data.summary');

    expect($summary['opportunities_won'])->toBe(1)
        ->and($summary['won_value_minor'])->toBe(10000);
});

it('reports per-course performance with completion rate', function () {
    seedReportFixture();
    Sanctum::actingAs(reportAdmin());

    $rows = collect($this->getJson('/api/v1/reports/insights/course-performance')->assertOk()->json('data.rows'));
    $row = $rows->first();

    expect($row['enrollments'])->toBe(1)
        ->and($row['completions'])->toBe(1)
        ->and((float) $row['completion_rate'])->toBe(100.0)
        ->and($row['revenue_minor'])->toBe(30000);
});

it('forbids non-admin users from every report endpoint', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/reports/insights/catalog')->assertForbidden();
    foreach (insightPaths() as $path) {
        $this->getJson("/api/v1/reports/insights/{$path}")->assertForbidden();
    }
});

it('requires authentication', function () {
    $this->getJson('/api/v1/reports/insights/revenue')->assertUnauthorized();
});
