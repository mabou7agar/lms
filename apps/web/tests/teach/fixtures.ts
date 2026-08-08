import { vi } from "vitest";
import type {
  CourseAnalytics,
  CoursePerformanceRow,
  DashboardOverview,
  InstructorAlerts,
  LearnerProgress,
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

export function learnerProgress(patch: Partial<LearnerProgress> = {}): LearnerProgress {
  return {
    student: { id: "usr-1", name: "Sara Learner" },
    current_lesson: { id: "les-3", title: "Eloquent Relationships", type: "video" },
    percent_complete: 40,
    // 5400s = 1h 30m; distinct from every count below so an assertion cannot pick the wrong node.
    watched_seconds: 5400,
    lessons_completed: 8,
    lessons_total: 20,
    last_activity_at: "2026-07-02T09:30:00+00:00",
    started_at: "2026-06-15T10:00:00+00:00",
    completed_at: null,
    assessments: { required: 3, passed: 2, all_required_passed: false },
    certificate: { issued: false },
    ...patch,
  };
}

export function courseAnalytics(patch: Partial<CourseAnalytics> = {}): CourseAnalytics {
  return {
    total_learners: available(60),
    watch_time: {
      total_watched_seconds: available(9000), // 2h 30m
      avg_watched_seconds_per_learner: available(4500), // 1h 15m
    },
    inactive_learners: { count: available(7), window_days: 14 },
    certificates_issued: available(11),
    lesson_drop_off: [
      { lesson: { id: "les-1", title: "Getting Started" }, started: 50, completed: 45, drop_off: 5 },
      { lesson: { id: "les-2", title: "Routing Deep Dive" }, started: 44, completed: 20, drop_off: 24 },
    ],
    completion_distribution: {
      "0": 3,
      "1-25": 6,
      "26-50": 9,
      "51-75": 12,
      "76-99": 4,
      "100": 26,
    },
    ...patch,
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
