<?php

namespace App\Contexts\Analytics\Services\Reports;

use App\Contexts\Analytics\Enums\InsightReport;
use App\Contexts\Commerce\Enums\TransactionStatus;
use App\Contexts\Commerce\Enums\TransactionType;
use App\Contexts\Commerce\Models\CouponRedemption;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\OrderItem;
use App\Contexts\Commerce\Models\PaymentTransaction;
use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Enums\LessonProgressStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LessonProgress;
use App\Domains\Certification\Enums\CertificateStatus;
use App\Domains\Certification\Models\Certificate;
use App\Domains\Crm\Models\ConsultingRequest;
use App\Domains\Crm\Models\CrmActivity;
use App\Domains\Crm\Models\Lead;
use App\Domains\Crm\Models\Opportunity;
use App\Domains\Crm\Models\Organization;
use App\Domains\Live\Enums\RegistrationStatus;
use App\Domains\Live\Models\LiveSession;
use App\Domains\Live\Models\SessionAttendance;
use App\Domains\Live\Models\SessionRegistration;
use App\Platform\Shared\Analytics\Data\Percentage;
use App\Platform\Shared\Services\BaseService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Reports read layer. This is the ONE auditable place where Analytics reads cross-context
 * OPERATIONAL tables (Commerce / Learning / Certification / Live / CRM / Catalog) to build
 * management reports. Every figure returned here comes from a real aggregate query — nothing is
 * synthesised. The (baselined) cross-context model imports above are intentionally concentrated
 * in this single class so the deptrac coupling surface stays localized and reviewable.
 *
 * All monetary values are integer minor units (e.g. halalas/cents); formatting is a frontend job.
 * Queries are parametrized by an inclusive [$from, $to] window on the most meaningful timestamp
 * for each report (paid_at for revenue, enrolled_at for learning, issued_at for certificates, …).
 */
class ReportingService extends BaseService
{
    /** @return array<int, array{key: string, label: string, description: string}> */
    public function catalog(): array
    {
        return InsightReport::catalog();
    }

    // ---------------------------------------------------------------------------------------
    // 1. Revenue
    // ---------------------------------------------------------------------------------------

    /**
     * Paid revenue (orders with paid_at set), refunds (succeeded refund transactions), net,
     * average order value and the monthly trend, plus a per-course revenue breakdown allocated
     * through order_items -> product_courses.
     *
     * @return array<string, mixed>
     */
    public function revenue(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $paid = fn (): \Illuminate\Database\Eloquent\Builder => Order::query()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to]);

        $gross = (int) $paid()->sum('total_minor');
        $ordersCount = (int) $paid()->count();
        $discounts = (int) $paid()->sum('discount_minor');

        $refundQuery = fn (): \Illuminate\Database\Eloquent\Builder => PaymentTransaction::query()
            ->where('type', TransactionType::Refund->value)
            ->where('status', TransactionStatus::Succeeded->value)
            ->whereBetween('created_at', [$from, $to]);

        $refunds = (int) $refundQuery()->sum('amount_minor');
        $net = $gross - $refunds;
        $aov = $ordersCount > 0 ? intdiv($gross, $ordersCount) : 0;

        // Per-course revenue: sum the order-item snapshot amount for paid orders, mapped to every
        // course the purchased product grants. A bundle product attributes its item amount to each
        // mapped course (documented over-attribution for bundles; single-course products are exact).
        $byCourse = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_courses', 'product_courses.product_id', '=', 'order_items.product_id')
            ->join('courses', 'courses.id', '=', 'product_courses.course_id')
            ->whereNotNull('orders.paid_at')
            ->whereBetween('orders.paid_at', [$from, $to])
            ->toBase()
            ->selectRaw('courses.title as course, SUM(order_items.unit_amount_minor) as revenue_minor, COUNT(*) as units')
            ->groupBy('courses.id', 'courses.title')
            ->orderByRaw('SUM(order_items.unit_amount_minor) DESC')
            ->limit(25)
            ->get()
            ->map(static fn ($r): array => [
                'course' => (string) $r->course,
                'revenue_minor' => (int) $r->revenue_minor,
                'units' => (int) $r->units,
            ])->values()->all();

        return [
            'summary' => [
                'gross_minor' => $gross,
                'refunds_minor' => $refunds,
                'net_minor' => $net,
                'discounts_minor' => $discounts,
                'orders' => $ordersCount,
                'aov_minor' => $aov,
            ],
            'series' => $this->monthlySeries($paid()->toBase(), 'paid_at', 'SUM(total_minor)'),
            'refund_series' => $this->monthlySeries($refundQuery()->toBase(), 'created_at', 'SUM(amount_minor)'),
            'by_course' => $byCourse,
        ];
    }

    // ---------------------------------------------------------------------------------------
    // 2. Commerce
    // ---------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function commerce(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byStatus = Order::query()
            ->whereBetween('created_at', [$from, $to])
            ->toBase()
            ->selectRaw('status, COUNT(*) as orders, COALESCE(SUM(total_minor), 0) as total_minor')
            ->groupBy('status')
            ->orderByRaw('COUNT(*) DESC')
            ->get()
            ->map(static fn ($r): array => [
                'status' => (string) $r->status,
                'orders' => (int) $r->orders,
                'total_minor' => (int) $r->total_minor,
            ])->values()->all();

        $couponRedemptions = (int) CouponRedemption::query()
            ->whereBetween('created_at', [$from, $to])->count();

        $discountTotal = (int) Order::query()
            ->whereNotNull('coupon_id')
            ->whereBetween('created_at', [$from, $to])
            ->sum('discount_minor');

        $enrollments = (int) Enrollment::query()
            ->whereBetween('enrolled_at', [$from, $to])->count();

        $paidOrders = (int) Order::query()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])->count();

        // Conversion proxy: paid orders relative to enrollments in the window (both real counts).
        $conversion = Percentage::rate($paidOrders, $enrollments);

        $topProducts = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotNull('orders.paid_at')
            ->whereBetween('orders.paid_at', [$from, $to])
            ->toBase()
            ->selectRaw('order_items.title as product, SUM(order_items.unit_amount_minor) as revenue_minor, COUNT(*) as units')
            ->groupBy('order_items.title')
            ->orderByRaw('SUM(order_items.unit_amount_minor) DESC')
            ->limit(15)
            ->get()
            ->map(static fn ($r): array => [
                'product' => (string) $r->product,
                'revenue_minor' => (int) $r->revenue_minor,
                'units' => (int) $r->units,
            ])->values()->all();

        return [
            'summary' => [
                'orders' => array_sum(array_column($byStatus, 'orders')),
                'paid_orders' => $paidOrders,
                'enrollments' => $enrollments,
                'conversion_rate' => $conversion,
                'coupon_redemptions' => $couponRedemptions,
                'discount_minor' => $discountTotal,
            ],
            'by_status' => $byStatus,
            'top_products' => $topProducts,
        ];
    }

    // ---------------------------------------------------------------------------------------
    // 3. Course Performance
    // ---------------------------------------------------------------------------------------

    /**
     * Per course: enrollments, completions, completion rate, average progress and paid revenue.
     *
     * @return array<string, mixed>
     */
    public function coursePerformance(CarbonImmutable $from, CarbonImmutable $to, int $page, int $perPage): array
    {
        $agg = Enrollment::query()
            ->whereBetween('enrolled_at', [$from, $to])
            ->toBase()
            ->selectRaw('course_id, COUNT(*) as enrollments')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as completions', [EnrollmentStatus::Completed->value])
            ->selectRaw('COALESCE(ROUND(AVG(progress_percentage)), 0) as avg_progress')
            ->groupBy('course_id')
            ->get();

        $courseIds = $agg->pluck('course_id')->map(static fn ($v): int => (int) $v)->all();

        /** @var array<int, string> $titles */
        $titles = DB::table('courses')->whereIn('id', $courseIds)->pluck('title', 'id')->all();

        /** @var array<int, int> $revenue */
        $revenue = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_courses', 'product_courses.product_id', '=', 'order_items.product_id')
            ->whereNotNull('orders.paid_at')
            ->whereBetween('orders.paid_at', [$from, $to])
            ->whereIn('product_courses.course_id', $courseIds === [] ? [0] : $courseIds)
            ->toBase()
            ->selectRaw('product_courses.course_id as course_id, SUM(order_items.unit_amount_minor) as revenue_minor')
            ->groupBy('product_courses.course_id')
            ->pluck('revenue_minor', 'course_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        $rows = $agg->map(function ($r) use ($titles, $revenue): array {
            $cid = (int) $r->course_id;
            $enrollments = (int) $r->enrollments;
            $completions = (int) $r->completions;

            return [
                'course' => (string) ($titles[$cid] ?? ''),
                'enrollments' => $enrollments,
                'completions' => $completions,
                'completion_rate' => Percentage::rate($completions, $enrollments),
                'avg_progress' => (int) $r->avg_progress,
                'revenue_minor' => (int) ($revenue[$cid] ?? 0),
            ];
        })->sortByDesc('enrollments')->values()->all();

        return $this->paginate($rows, $page, $perPage);
    }

    // ---------------------------------------------------------------------------------------
    // 4. Instructor Performance
    // ---------------------------------------------------------------------------------------

    /**
     * Per instructor (via the course_trainer pivot): courses trained, unique students, completions
     * and attributable paid revenue. No rating data exists in the model, so rating is omitted.
     *
     * @return array<string, mixed>
     */
    public function instructorPerformance(CarbonImmutable $from, CarbonImmutable $to, int $page, int $perPage): array
    {
        /** @var array<int, int> $courseCounts instructor user_id => courses trained */
        $courseCounts = DB::table('course_trainer')
            ->selectRaw('user_id, COUNT(*) as courses')
            ->groupBy('user_id')
            ->pluck('courses', 'user_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        $agg = Enrollment::query()
            ->join('course_trainer', 'course_trainer.course_id', '=', 'enrollments.course_id')
            ->whereBetween('enrollments.enrolled_at', [$from, $to])
            ->toBase()
            ->selectRaw('course_trainer.user_id as instructor_id')
            ->selectRaw('COUNT(*) as enrollments')
            ->selectRaw('COUNT(DISTINCT enrollments.user_id) as students')
            ->selectRaw('COALESCE(SUM(CASE WHEN enrollments.status = ? THEN 1 ELSE 0 END), 0) as completions', [EnrollmentStatus::Completed->value])
            ->groupBy('course_trainer.user_id')
            ->get();

        /** @var array<int, int> $revenue */
        $revenue = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_courses', 'product_courses.product_id', '=', 'order_items.product_id')
            ->join('course_trainer', 'course_trainer.course_id', '=', 'product_courses.course_id')
            ->whereNotNull('orders.paid_at')
            ->whereBetween('orders.paid_at', [$from, $to])
            ->toBase()
            ->selectRaw('course_trainer.user_id as instructor_id, SUM(order_items.unit_amount_minor) as revenue_minor')
            ->groupBy('course_trainer.user_id')
            ->pluck('revenue_minor', 'instructor_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        $instructorIds = $agg->pluck('instructor_id')->map(static fn ($v): int => (int) $v)->all();
        /** @var array<int, string> $names */
        $names = DB::table('users')->whereIn('id', $instructorIds === [] ? [0] : $instructorIds)->pluck('name', 'id')->all();

        $rows = $agg->map(function ($r) use ($courseCounts, $revenue, $names): array {
            $id = (int) $r->instructor_id;

            return [
                'instructor' => (string) ($names[$id] ?? ''),
                'courses' => (int) ($courseCounts[$id] ?? 0),
                'students' => (int) $r->students,
                'enrollments' => (int) $r->enrollments,
                'completions' => (int) $r->completions,
                'revenue_minor' => (int) ($revenue[$id] ?? 0),
            ];
        })->sortByDesc('students')->values()->all();

        return $this->paginate($rows, $page, $perPage);
    }

    // ---------------------------------------------------------------------------------------
    // 5. Organization Performance
    // ---------------------------------------------------------------------------------------

    /**
     * Per CRM organization: members, active members (seats used), active learners, enrollments and
     * completions. Learner metrics join organization_members -> enrollments on user_id.
     *
     * @return array<string, mixed>
     */
    public function organizationPerformance(CarbonImmutable $from, CarbonImmutable $to, int $page, int $perPage): array
    {
        $members = DB::table('organization_members')
            ->selectRaw('organization_id, COUNT(*) as members')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) as active_members")
            ->groupBy('organization_id')
            ->get()
            ->keyBy('organization_id');

        $learning = Enrollment::query()
            ->join('organization_members', 'organization_members.user_id', '=', 'enrollments.user_id')
            ->whereBetween('enrollments.enrolled_at', [$from, $to])
            ->toBase()
            ->selectRaw('organization_members.organization_id as org_id')
            ->selectRaw('COUNT(*) as enrollments')
            ->selectRaw('COUNT(DISTINCT enrollments.user_id) as active_learners')
            ->selectRaw('COALESCE(SUM(CASE WHEN enrollments.status = ? THEN 1 ELSE 0 END), 0) as completions', [EnrollmentStatus::Completed->value])
            ->groupBy('organization_members.organization_id')
            ->get()
            ->keyBy('org_id');

        $rows = Organization::query()
            ->orderBy('name')
            ->toBase()
            ->get(['id', 'name'])
            ->map(function ($org) use ($members, $learning): array {
                $m = $members->get($org->id);
                $l = $learning->get($org->id);

                return [
                    'organization' => (string) $org->name,
                    'members' => (int) ($m->members ?? 0),
                    'seats_used' => (int) ($m->active_members ?? 0),
                    'active_learners' => (int) ($l->active_learners ?? 0),
                    'enrollments' => (int) ($l->enrollments ?? 0),
                    'completions' => (int) ($l->completions ?? 0),
                ];
            })
            ->sortByDesc('members')
            ->values()
            ->all();

        return $this->paginate($rows, $page, $perPage);
    }

    // ---------------------------------------------------------------------------------------
    // 6. Certificates
    // ---------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function certificates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $issued = (int) Certificate::query()
            ->where('status', CertificateStatus::Issued->value)
            ->whereBetween('issued_at', [$from, $to])->count();

        $revoked = (int) Certificate::query()
            ->where('status', CertificateStatus::Revoked->value)
            ->whereBetween('revoked_at', [$from, $to])->count();

        $series = $this->monthlySeries(
            Certificate::query()
                ->where('status', CertificateStatus::Issued->value)
                ->whereBetween('issued_at', [$from, $to])->toBase(),
            'issued_at',
            'COUNT(*)'
        );

        $byCourse = Certificate::query()
            ->join('courses', 'courses.id', '=', 'certificates.course_id')
            ->where('certificates.status', CertificateStatus::Issued->value)
            ->whereBetween('certificates.issued_at', [$from, $to])
            ->toBase()
            ->selectRaw('courses.title as course, COUNT(*) as issued')
            ->groupBy('courses.id', 'courses.title')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(25)
            ->get()
            ->map(static fn ($r): array => ['course' => (string) $r->course, 'issued' => (int) $r->issued])
            ->values()->all();

        $recent = Certificate::query()
            ->join('courses', 'courses.id', '=', 'certificates.course_id')
            ->whereBetween('certificates.issued_at', [$from, $to])
            ->toBase()
            ->selectRaw('certificates.number as number, certificates.status as status, courses.title as course, certificates.issued_at as issued_at')
            ->orderByRaw('certificates.issued_at DESC')
            ->limit(15)
            ->get()
            ->map(static fn ($r): array => [
                'number' => (string) $r->number,
                'status' => (string) $r->status,
                'course' => (string) $r->course,
                'issued_at' => $r->issued_at === null ? null : (string) $r->issued_at,
            ])->values()->all();

        return [
            'summary' => [
                'issued' => $issued,
                'revoked' => $revoked,
            ],
            'series' => $series,
            'by_course' => $byCourse,
            'recent' => $recent,
        ];
    }

    // ---------------------------------------------------------------------------------------
    // 7. Live Sessions
    // ---------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function liveSessions(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byStatus = LiveSession::query()
            ->whereBetween('starts_at', [$from, $to])
            ->toBase()
            ->selectRaw('status, COUNT(*) as sessions')
            ->groupBy('status')
            ->get()
            ->map(static fn ($r): array => ['status' => (string) $r->status, 'sessions' => (int) $r->sessions])
            ->values()->all();

        $totalSessions = array_sum(array_column($byStatus, 'sessions'));
        $completed = 0;
        foreach ($byStatus as $s) {
            if ($s['status'] === 'completed') {
                $completed = $s['sessions'];
            }
        }

        $registrations = (int) SessionRegistration::query()
            ->join('live_sessions', 'live_sessions.id', '=', 'session_registrations.session_id')
            ->whereBetween('live_sessions.starts_at', [$from, $to])->count();

        $waitlisted = (int) SessionRegistration::query()
            ->join('live_sessions', 'live_sessions.id', '=', 'session_registrations.session_id')
            ->where('session_registrations.status', RegistrationStatus::Waitlisted->value)
            ->whereBetween('live_sessions.starts_at', [$from, $to])->count();

        $attendances = (int) SessionAttendance::query()
            ->join('live_sessions', 'live_sessions.id', '=', 'session_attendances.session_id')
            ->whereBetween('live_sessions.starts_at', [$from, $to])->count();

        $rows = LiveSession::query()
            ->whereBetween('starts_at', [$from, $to])
            ->toBase()
            ->selectRaw('live_sessions.title as title, live_sessions.status as status, live_sessions.starts_at as starts_at')
            ->selectRaw('(SELECT COUNT(*) FROM session_registrations sr WHERE sr.session_id = live_sessions.id) as registrations')
            ->selectRaw('(SELECT COUNT(*) FROM session_attendances sa WHERE sa.session_id = live_sessions.id) as attendances')
            ->orderByRaw('live_sessions.starts_at DESC')
            ->limit(50)
            ->get()
            ->map(static function ($r): array {
                $reg = (int) $r->registrations;
                $att = (int) $r->attendances;

                return [
                    'title' => (string) $r->title,
                    'status' => (string) $r->status,
                    'starts_at' => $r->starts_at === null ? null : (string) $r->starts_at,
                    'registrations' => $reg,
                    'attendances' => $att,
                    'attendance_rate' => Percentage::rate($att, $reg),
                ];
            })->values()->all();

        return [
            'summary' => [
                'sessions' => $totalSessions,
                'completed' => $completed,
                'completion_rate' => Percentage::rate($completed, $totalSessions),
                'registrations' => $registrations,
                'attendances' => $attendances,
                'attendance_rate' => Percentage::rate($attendances, $registrations),
                'waitlisted' => $waitlisted,
            ],
            'by_status' => $byStatus,
            'sessions' => $rows,
        ];
    }

    // ---------------------------------------------------------------------------------------
    // 8. Learner Activity
    // ---------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function learnerActivity(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $enrollmentsSeries = $this->monthlySeries(
            Enrollment::query()->whereBetween('enrolled_at', [$from, $to])->toBase(),
            'enrolled_at',
            'COUNT(*)'
        );

        $lessonsSeries = $this->monthlySeries(
            LessonProgress::query()
                ->where('status', LessonProgressStatus::Completed->value)
                ->whereBetween('completed_at', [$from, $to])->toBase(),
            'completed_at',
            'COUNT(*)'
        );

        // Active learners = distinct enrollment owners with a completed lesson in the month. No login
        // event stream exists, so lesson-completion recency is the honest activity signal.
        $activeSeries = LessonProgress::query()
            ->join('enrollments', 'enrollments.id', '=', 'lesson_progress.enrollment_id')
            ->whereNotNull('lesson_progress.completed_at')
            ->whereBetween('lesson_progress.completed_at', [$from, $to])
            ->toBase()
            ->selectRaw("to_char(lesson_progress.completed_at, 'YYYY-MM') as period, COUNT(DISTINCT enrollments.user_id) as value")
            ->groupByRaw("to_char(lesson_progress.completed_at, 'YYYY-MM')")
            ->orderByRaw("to_char(lesson_progress.completed_at, 'YYYY-MM')")
            ->get()
            ->map(static fn ($r): array => ['period' => (string) $r->period, 'value' => (int) $r->value])
            ->values()->all();

        $activeTotal = (int) LessonProgress::query()
            ->join('enrollments', 'enrollments.id', '=', 'lesson_progress.enrollment_id')
            ->whereNotNull('lesson_progress.completed_at')
            ->whereBetween('lesson_progress.completed_at', [$from, $to])
            ->distinct()
            ->count('enrollments.user_id');

        return [
            'summary' => [
                'active_learners' => $activeTotal,
                'lessons_completed' => (int) array_sum(array_column($lessonsSeries, 'value')),
                'enrollments' => (int) array_sum(array_column($enrollmentsSeries, 'value')),
            ],
            'active_learners_series' => $activeSeries,
            'lessons_completed_series' => $lessonsSeries,
            'enrollments_series' => $enrollmentsSeries,
        ];
    }

    // ---------------------------------------------------------------------------------------
    // 9. Completion Funnel
    // ---------------------------------------------------------------------------------------

    /**
     * Enrolled -> started -> in-progress -> completed -> certified, over enrollments whose
     * enrolled_at falls in the window. Step definitions (all deterministic from real columns):
     *   - enrolled:    every enrollment in the window.
     *   - started:     enrolled AND progress_percentage > 0 (learner has made measurable progress).
     *   - in_progress: enrolled AND status = active AND progress_percentage BETWEEN 1 AND 99.
     *   - completed:   enrolled AND status = completed.
     *   - certified:   enrolled AND an issued certificate exists for the same (user_id, course_id).
     *
     * @return array<string, mixed>
     */
    public function completionFunnel(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $inWindow = fn (): \Illuminate\Database\Eloquent\Builder => Enrollment::query()
            ->whereBetween('enrolled_at', [$from, $to]);

        $enrolled = (int) $inWindow()->count();
        $started = (int) $inWindow()->where('progress_percentage', '>', 0)->count();
        $inProgress = (int) $inWindow()
            ->where('status', EnrollmentStatus::Active->value)
            ->whereBetween('progress_percentage', [1, 99])->count();
        $completed = (int) $inWindow()->where('status', EnrollmentStatus::Completed->value)->count();

        $certified = (int) Enrollment::query()
            ->whereBetween('enrollments.enrolled_at', [$from, $to])
            ->join('certificates', function ($join): void {
                $join->on('certificates.user_id', '=', 'enrollments.user_id')
                    ->on('certificates.course_id', '=', 'enrollments.course_id');
            })
            ->where('certificates.status', CertificateStatus::Issued->value)
            ->distinct()
            ->count('enrollments.id');

        $pct = static fn (int $n): float => Percentage::rate($n, $enrolled);

        $steps = [
            ['step' => 'enrolled', 'count' => $enrolled, 'percentage' => $pct($enrolled)],
            ['step' => 'started', 'count' => $started, 'percentage' => $pct($started)],
            ['step' => 'in_progress', 'count' => $inProgress, 'percentage' => $pct($inProgress)],
            ['step' => 'completed', 'count' => $completed, 'percentage' => $pct($completed)],
            ['step' => 'certified', 'count' => $certified, 'percentage' => $pct($certified)],
        ];

        return ['steps' => $steps];
    }

    // ---------------------------------------------------------------------------------------
    // 10. Retention
    // ---------------------------------------------------------------------------------------

    /**
     * Returning-learner cohorts. Definition (deterministic from real enrollment + lesson-progress
     * dates; no login stream is fabricated):
     *   - A learner's COHORT is the calendar month of their FIRST enrollment (MIN(enrolled_at)).
     *   - Only cohorts whose first enrollment falls within [$from, $to] are reported.
     *   - A learner is RETAINED if they have any completed lesson (lesson_progress.completed_at)
     *     strictly AFTER the last day of their cohort month — i.e. real activity in a later month.
     *   - retention_rate = retained / cohort_size (%).
     *
     * @return array<string, mixed>
     */
    public function retention(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // C5: computed entirely in SQL. The prior version materialized one row per user (first
        // enrollment + last activity) into PHP and bucketed there, so memory scaled with the user
        // count and OOM'd at volume. This groups the SAME definition in one query whose result is one
        // row per cohort month — bounded by the window, not the user count.
        //
        // Semantics preserved exactly:
        //   - cohort           = calendar month of MIN(enrolled_at) per user (computed over ALL
        //                        enrollments, then filtered to first-enrollments within [from, to]).
        //   - retained         = last completed_at is strictly AFTER the cohort month. "> the last
        //                        microsecond of the month" is, for any real timestamp, identical to
        //                        ">= the first day of the following month", which is what the SQL uses.
        //   - full-precision bindings so the [from, to] boundary matches the prior Carbon comparison.
        //   - soft-deleted enrollments are excluded (deleted_at is null) in BOTH CTEs, matching the
        //     Enrollment model's SoftDeletes global scope. Raw SQL bypasses Eloquent scopes, so this
        //     must be explicit — otherwise a revoked/refunded enrollment would still inflate a cohort
        //     (and, if it was the user's earliest, could even mis-assign their cohort month).
        $rows = DB::select(
            <<<'SQL'
            with first_enroll as (
                select user_id, min(enrolled_at) as fe
                from enrollments
                where deleted_at is null
                group by user_id
            ),
            last_activity as (
                select e.user_id, max(lp.completed_at) as la
                from lesson_progress lp
                join enrollments e on e.id = lp.enrollment_id
                where lp.completed_at is not null
                  and e.deleted_at is null
                group by e.user_id
            )
            select
                to_char(date_trunc('month', fe.fe), 'YYYY-MM') as cohort,
                count(*) as cohort_size,
                count(*) filter (where la.la >= date_trunc('month', fe.fe) + interval '1 month') as retained
            from first_enroll fe
            left join last_activity la on la.user_id = fe.user_id
            where fe.fe >= ? and fe.fe <= ?
            group by date_trunc('month', fe.fe)
            order by date_trunc('month', fe.fe)
            SQL,
            [$from->format('Y-m-d H:i:s.u'), $to->format('Y-m-d H:i:s.u')],
        );

        $cohorts = collect($rows)->map(function ($row): array {
            $size = (int) $row->cohort_size;
            $retained = (int) $row->retained;

            return [
                'cohort' => (string) $row->cohort,
                'cohort_size' => $size,
                'retained' => $retained,
                'retention_rate' => Percentage::rate($retained, $size),
            ];
        })->all();

        return ['cohorts' => $cohorts];
    }

    // ---------------------------------------------------------------------------------------
    // 11. CRM
    // ---------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function crm(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $pipeline = Lead::query()
            ->leftJoin('crm_stages', 'crm_stages.id', '=', 'crm_leads.stage_id')
            ->toBase()
            ->selectRaw("COALESCE(crm_stages.name, 'Unassigned') as stage, COUNT(*) as leads, COALESCE(SUM(crm_leads.value_minor), 0) as value_minor")
            ->groupByRaw("COALESCE(crm_stages.name, 'Unassigned')")
            ->orderByRaw('COUNT(*) DESC')
            ->get()
            ->map(static fn ($r): array => [
                'stage' => (string) $r->stage,
                'leads' => (int) $r->leads,
                'value_minor' => (int) $r->value_minor,
            ])->values()->all();

        $leadsByStatus = Lead::query()
            ->whereBetween('created_at', [$from, $to])
            ->toBase()
            ->selectRaw('status, COUNT(*) as leads')
            ->groupBy('status')
            ->get()
            ->map(static fn ($r): array => ['status' => (string) $r->status, 'leads' => (int) $r->leads])
            ->values()->all();

        $opps = Opportunity::query()
            ->whereBetween('created_at', [$from, $to])
            ->toBase()
            ->selectRaw('status, COUNT(*) as opportunities, COALESCE(SUM(amount_minor), 0) as value_minor')
            ->groupBy('status')
            ->get();

        $oppByStatus = $opps->mapWithKeys(static fn ($r): array => [
            (string) $r->status => ['count' => (int) $r->opportunities, 'value_minor' => (int) $r->value_minor],
        ])->all();

        $activities = CrmActivity::query()
            ->whereBetween('created_at', [$from, $to])
            ->toBase()
            ->selectRaw('type, COUNT(*) as activities')
            ->groupBy('type')
            ->get()
            ->map(static fn ($r): array => ['type' => (string) $r->type, 'activities' => (int) $r->activities])
            ->values()->all();

        $consulting = ConsultingRequest::query()
            ->whereBetween('created_at', [$from, $to])
            ->toBase()
            ->selectRaw('status, COUNT(*) as requests')
            ->groupBy('status')
            ->get()
            ->map(static fn ($r): array => ['status' => (string) $r->status, 'requests' => (int) $r->requests])
            ->values()->all();

        return [
            'summary' => [
                'leads' => (int) array_sum(array_column($leadsByStatus, 'leads')),
                'opportunities_open' => (int) ($oppByStatus['open']['count'] ?? 0),
                'opportunities_won' => (int) ($oppByStatus['won']['count'] ?? 0),
                'opportunities_lost' => (int) ($oppByStatus['lost']['count'] ?? 0),
                'won_value_minor' => (int) ($oppByStatus['won']['value_minor'] ?? 0),
                'activities' => (int) array_sum(array_column($activities, 'activities')),
                'consulting_requests' => (int) array_sum(array_column($consulting, 'requests')),
            ],
            'pipeline_by_stage' => $pipeline,
            'leads_by_status' => $leadsByStatus,
            'opportunities_by_status' => $opps->map(static fn ($r): array => [
                'status' => (string) $r->status,
                'opportunities' => (int) $r->opportunities,
                'value_minor' => (int) $r->value_minor,
            ])->values()->all(),
            'activities_by_type' => $activities,
            'consulting_by_status' => $consulting,
        ];
    }

    // ---------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------

    /**
     * Group a pre-filtered base query into a month-bucketed series. $valueSql is a raw aggregate
     * expression (e.g. 'SUM(total_minor)', 'COUNT(*)') over the same query.
     *
     * @return array<int, array{period: string, value: int}>
     */
    private function monthlySeries(Builder $query, string $dateColumn, string $valueSql): array
    {
        return $query
            ->selectRaw("to_char({$dateColumn}, 'YYYY-MM') as period, {$valueSql} as value")
            ->groupByRaw("to_char({$dateColumn}, 'YYYY-MM')")
            ->orderByRaw("to_char({$dateColumn}, 'YYYY-MM')")
            ->get()
            ->map(static fn ($r): array => ['period' => (string) $r->period, 'value' => (int) $r->value])
            ->values()
            ->all();
    }

    /**
     * Slice a fully-computed, sorted row set into the standard paginated envelope shape.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function paginate(array $rows, int $page, int $perPage): array
    {
        $total = count($rows);
        $lastPage = $perPage > 0 ? (int) max(1, (int) ceil($total / $perPage)) : 1;
        $page = min(max(1, $page), $lastPage);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'rows' => $slice,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ];
    }
}
