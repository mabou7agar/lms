<?php

namespace App\Contexts\Analytics\Enums;

/**
 * Catalog of the operational reports exposed under /api/v1/reports/insights/*. Each case is a
 * report the ReportingService can compute from real source tables. Kept as a single source of
 * truth shared by the controller (routing/dispatch), the catalog endpoint, and the seeder that
 * registers a matching ReportDefinition so every report has an admin surface.
 */
enum InsightReport: string
{
    case Revenue = 'revenue';
    case Commerce = 'commerce';
    case CoursePerformance = 'course_performance';
    case InstructorPerformance = 'instructor_performance';
    case OrganizationPerformance = 'organization_performance';
    case Certificates = 'certificates';
    case LiveSessions = 'live_sessions';
    case LearnerActivity = 'learner_activity';
    case CompletionFunnel = 'completion_funnel';
    case Retention = 'retention';
    case Crm = 'crm';
    case AdminSummary = 'admin_summary';
    case MarketingFunnel = 'marketing_funnel';
    case Accounting = 'accounting';

    public function label(): string
    {
        return match ($this) {
            self::Revenue => 'Revenue',
            self::Commerce => 'Commerce',
            self::CoursePerformance => 'Course Performance',
            self::InstructorPerformance => 'Instructor Performance',
            self::OrganizationPerformance => 'Organization Performance',
            self::Certificates => 'Certificates',
            self::LiveSessions => 'Live Sessions',
            self::LearnerActivity => 'Learner Activity',
            self::CompletionFunnel => 'Completion Funnel',
            self::Retention => 'Retention',
            self::Crm => 'CRM',
            self::AdminSummary => 'Executive Summary',
            self::MarketingFunnel => 'Marketing Funnel',
            self::Accounting => 'Accounting',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Revenue => 'Paid revenue, refunds, net, AOV and monthly trend with per-course breakdown.',
            self::Commerce => 'Orders by status, coupons, discounts, conversion and top products.',
            self::CoursePerformance => 'Enrollments, completions, completion rate, average progress and revenue per course.',
            self::InstructorPerformance => 'Courses, unique students, completions and attributable revenue per instructor.',
            self::OrganizationPerformance => 'Members, active learners, enrollments, completions and seats used per organization.',
            self::Certificates => 'Certificates issued over time, by course, and revoked counts.',
            self::LiveSessions => 'Sessions by status, registrations, attendance rate and waitlist.',
            self::LearnerActivity => 'Active learners, lessons completed and enrollments over time.',
            self::CompletionFunnel => 'Enrolled to started to in-progress to completed to certified funnel.',
            self::Retention => 'Returning-learner cohorts by first-enrollment month.',
            self::Crm => 'Pipeline by stage, leads, opportunities, activities and consulting requests.',
            self::AdminSummary => 'Gross and net revenue, refunds, B2C vs B2B, course vs bundle, top courses, bundles and companies, seats, certificates and Q&A health.',
            self::MarketingFunnel => 'View to cart to checkout to paid, built from the event log, with abandoned carts, coupons, search terms and traffic sources.',
            self::Accounting => 'Orders by status, invoices, credit notes, refunds and tax collected, split by who was invoiced.',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** @return array<int, array{key: string, label: string, description: string}> */
    public static function catalog(): array
    {
        return array_map(static fn (self $c): array => [
            'key' => $c->value,
            'label' => $c->label(),
            'description' => $c->description(),
        ], self::cases());
    }
}
