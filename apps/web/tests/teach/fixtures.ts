import { vi } from "vitest";
import type {
  CoursePerformanceRow,
  DashboardOverview,
  InstructorAlerts,
  MetricValue,
} from "@/lib/teach/api";

/** A settled query result. Matches the shape QueryState reads. */
export const ok = <T,>(data: T) => ({
  data,
  isPending: false,
  isError: false,
  error: null,
  refetch: vi.fn(),
});

export const pending = () => ({
  data: undefined,
  isPending: true,
  isError: false,
  error: null,
  refetch: vi.fn(),
});

export const failed = (message = "Boom") => ({
  data: undefined,
  isPending: false,
  isError: true,
  error: new Error(message),
  refetch: vi.fn(),
});

export const idle = () => ({ ...pending(), isPending: false, data: undefined });

export const available = (value: number): MetricValue => ({ value, available: true });
export const unavailable = (reason: string): MetricValue => ({ value: null, available: false, reason });

export function overview(patch: Partial<DashboardOverview> = {}): DashboardOverview {
  return {
    total_courses: available(12),
    published_courses: available(7),
    draft_courses: available(4),
    archived_courses: available(1),
    total_learners: available(1234),
    active_learners: available(310),
    completion_rate: available(42),
    average_progress: available(68),
    assessment_pass_rate: unavailable("No graded quiz attempts yet."),
    revenue: unavailable("Revenue analytics are not available for instructors yet."),
    at_risk_learners: unavailable("At-risk learner detection is not configured."),
    ...patch,
  };
}

export function performanceRow(patch: Partial<CoursePerformanceRow> = {}): CoursePerformanceRow {
  return {
    id: "crs-1",
    title: "Advanced Laravel",
    slug: "advanced-laravel",
    status: "published",
    sections: 4,
    lessons: 21,
    // Deliberately all different. Equal values make an assertion like getByText("50") ambiguous,
    // and a test that cannot tell two columns apart is not testing the columns.
    enrollment_count: available(50),
    unique_learners: available(48),
    active_learners: available(9),
    completion_rate: available(30),
    average_progress: available(55),
    assessment_pass_rate: unavailable("No graded quiz attempts yet."),
    publish_blocker_count: 0,
    warning_count: 0,
    readiness_score: 100,
    is_publishable: true,
    last_updated_at: "2026-07-01T10:00:00+00:00",
    last_published_at: "2026-06-20T10:00:00+00:00",
    revenue: unavailable("Revenue analytics are not available for instructors yet."),
    ...patch,
  };
}

export function page<T>(rows: T[], patch: Partial<{ current_page: number; last_page: number; total: number; per_page: number }> = {}) {
  return {
    data: rows,
    meta: {
      current_page: 1,
      per_page: 15,
      total: rows.length,
      last_page: 1,
      ...patch,
    },
    links: { first: null, last: null, prev: null, next: null },
  };
}

export function alerts(patch: Partial<InstructorAlerts> = {}): InstructorAlerts {
  return {
    publish_blockers: [],
    warnings: [],
    readiness_coverage: { evaluated_count: 3, total_count: 3, truncated: false, limit: 50 },
    stale_drafts: [],
    courses_without_learners: [],
    at_risk_learners: { available: false, reason: "At-risk learner detection is not configured." },
    failed_publishes: { available: false, reason: "Failed publish attempts are not recorded." },
    ...patch,
  };
}
