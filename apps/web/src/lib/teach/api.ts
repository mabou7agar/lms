import { api } from "@/lib/api/client";
import type { ApiSuccess, Paginated } from "@/types/api";

export type CourseStatus = "draft" | "published" | "archived";

export type TeachCourseStats = {
  enrollments: number;
  completions: number;
  avg_progress: number;
  sections: number;
  lessons: number;
};

export type TeachCourse = {
  id: string;
  title: string;
  slug: string;
  subtitle: string | null;
  status: CourseStatus;
  visibility: string;
  is_featured: boolean;
  thumbnail_path: string | null;
  published_at: string | null;
  stats: TeachCourseStats | null;
};

export type RecentEnrollment = {
  course: { id: string; title: string };
  student: { id: string | null; name: string | null };
  status: string;
  progress_percentage: number;
  enrolled_at: string | null;
};

export type TeachDashboard = {
  courses: { total: number; draft: number; published: number; archived: number };
  students: number;
  completions: number;
  recent_enrollments: RecentEnrollment[];
};

export type TeachStudent = {
  enrollment_id: string;
  student: { id: string | null; name: string | null };
  status: string;
  progress_percentage: number;
  enrolled_at: string | null;
  completed_at: string | null;
};

export type TeachAnnouncement = {
  id: string;
  title: string;
  body: string;
  published_at: string | null;
  created_at: string | null;
};

export type AnnouncementInput = { title: string; body: string };

export type ReadinessSeverity = "blocker" | "warning";

/**
 * One publish-readiness finding. `code` is the stable identifier — key UI decisions and deep links
 * off it, never off `title`, which is server-authored prose and may be reworded.
 */
export type ReadinessIssue = {
  code: string;
  severity: ReadinessSeverity;
  title: string;
  explanation: string;
  recommended_action: string;
  entity_type: "course" | "section" | "lesson" | null;
  entity_id: string | null;
};

/**
 * The server's verdict on whether a course may publish.
 *
 * `is_publishable` is read directly and never recomputed from `blockers`: the backend owns that
 * decision and derives its own publish guard from the same evaluation. Deriving it again here
 * would create a second rule set that can drift.
 */
export type ReadinessReport = {
  is_publishable: boolean;
  score: number;
  evaluated_at: string;
  blockers: ReadinessIssue[];
  warnings: ReadinessIssue[];
  passed_checks: string[];
};

/**
 * The availability envelope every dashboard metric arrives in.
 *
 * `available: false` with `value: null` is the ONLY correct shape for a metric the backend cannot
 * answer. The client must never substitute 0 — nobody failing a quiz is not the same as nobody
 * sitting one, and a revenue figure of 0 for an instructor with no revenue backend is a false
 * statement about their earnings. `reason` is server prose meant to be shown verbatim.
 */
export type MetricValue = {
  value: number | null;
  available: boolean;
  reason?: string;
};

/** Keys the overview endpoint returns. Kept explicit so a renamed metric is a compile error. */
export type OverviewMetricKey =
  | "total_courses"
  | "published_courses"
  | "draft_courses"
  | "archived_courses"
  | "total_learners"
  | "active_learners"
  | "completion_rate"
  | "average_progress"
  | "assessment_pass_rate"
  | "revenue"
  | "at_risk_learners";

export type DashboardOverview = Record<OverviewMetricKey, MetricValue>;

/** Sortable columns. Mirrors CoursePerformanceService::SORTABLE — anything else is a 422. */
export const PERFORMANCE_SORT_FIELDS = [
  "title",
  "status",
  "created_at",
  "updated_at",
  "published_at",
] as const;
export type PerformanceSortField = (typeof PERFORMANCE_SORT_FIELDS)[number];
export type SortDirection = "asc" | "desc";

export type PerformanceFilters = {
  search?: string;
  status?: CourseStatus;
  course?: string;
  sort?: PerformanceSortField;
  direction?: SortDirection;
  page?: number;
  per_page?: number;
  date_from?: string;
  date_to?: string;
};

export type CoursePerformanceRow = {
  id: string;
  title: string;
  slug: string;
  status: CourseStatus;
  sections: number;
  lessons: number;
  enrollment_count: MetricValue;
  unique_learners: MetricValue;
  active_learners: MetricValue;
  completion_rate: MetricValue;
  average_progress: MetricValue;
  assessment_pass_rate: MetricValue;
  publish_blocker_count: number;
  warning_count: number;
  readiness_score: number;
  is_publishable: boolean;
  last_updated_at: string | null;
  last_published_at: string | null;
  revenue: MetricValue;
};

export type ActivityEntry = {
  id: string;
  title: string;
  status: CourseStatus;
  occurred_at: string | null;
};

export type AuthoringActivity = {
  recently_edited: ActivityEntry[];
  recently_published: ActivityEntry[];
};

/**
 * How much of the catalogue the readiness sweep actually covered.
 *
 * Readiness is bounded server-side, so a caller with more courses than `limit` gets a PARTIAL
 * answer. The UI must say so — presenting a truncated sweep as "no blockers" is the failure mode
 * this metadata exists to prevent.
 */
export type ReadinessCoverage = {
  evaluated_count: number;
  total_count: number;
  truncated: boolean;
  limit: number;
};

export type BlockedCourseAlert = {
  id: string;
  title: string;
  status: CourseStatus;
  blocker_count: number;
  first_blocker: string | null;
};

export type WarnedCourseAlert = {
  id: string;
  title: string;
  status: CourseStatus;
  warning_count: number;
};

export type StaleDraftAlert = { id: string; title: string; last_updated_at: string | null };
export type CourseRefAlert = { id: string; title: string };

/** An alert stream the backend cannot compute. Carries a reason, never an empty list. */
export type UnavailableSignal = { available: false; reason: string };

export type InstructorAlerts = {
  publish_blockers: BlockedCourseAlert[];
  warnings: WarnedCourseAlert[];
  readiness_coverage: ReadinessCoverage;
  stale_drafts: StaleDraftAlert[];
  courses_without_learners: CourseRefAlert[];
  at_risk_learners: UnavailableSignal;
  failed_publishes: UnavailableSignal;
};

/**
 * Draft-vs-published comparison. Always `available: false` today — no publication snapshot is
 * persisted, so there is nothing to compare against.
 *
 * The unavailable branch deliberately has NO `changes` key. "No changes" and "we cannot tell" are
 * different statements, and only the second is true; a client that could read an empty `changes`
 * array would render the first.
 */
export type ChangeSummary =
  | { available: false; reason: string }
  | {
      available: true;
      baseline_published_at: string | null;
      changes: Record<string, unknown>;
    };

/** Drops empty values so the request only ever carries parameters the backend accepts. */
function performanceQuery(filters: PerformanceFilters): string {
  const params = new URLSearchParams();

  for (const [key, value] of Object.entries(filters)) {
    if (value === undefined || value === null || value === "") continue;
    params.set(key, String(value));
  }

  const query = params.toString();
  return query ? `?${query}` : "";
}

// ---- Reads (unwrap `.data`) ----
export const getTeachDashboard = () => api.data<TeachDashboard>("teach/dashboard");

export const getDashboardOverview = (from?: string, to?: string) =>
  api.data<DashboardOverview>(`teach/dashboard/overview${performanceQuery({ date_from: from, date_to: to })}`);

export const getCoursePerformance = (filters: PerformanceFilters) =>
  api.get<Paginated<CoursePerformanceRow>>(`teach/dashboard/courses${performanceQuery(filters)}`);

export const getAuthoringActivity = () => api.data<AuthoringActivity>("teach/dashboard/activity");

export const getInstructorAlerts = () => api.data<InstructorAlerts>("teach/dashboard/alerts");

export const getCourseChanges = (id: string) =>
  api.data<ChangeSummary>(`teach/courses/${id}/changes`);

export const getTeachCourses = (status?: CourseStatus) =>
  api.data<TeachCourse[]>(`teach/courses${status ? `?status=${status}` : ""}`);

export const getTeachCourse = (id: string) => api.data<TeachCourse>(`teach/courses/${id}`);

export const getTeachStudents = (id: string, page = 1) =>
  api.get<Paginated<TeachStudent>>(`teach/courses/${id}/students?page=${page}`);

export const getTeachAnnouncements = (id: string) =>
  api.data<TeachAnnouncement[]>(`teach/courses/${id}/announcements`);

export const getCourseReadiness = (id: string) =>
  api.data<ReadinessReport>(`teach/courses/${id}/readiness`);

// ---- Writes ----
export const publishCourse = (id: string) =>
  api.post<ApiSuccess<TeachCourse>>(`teach/courses/${id}/publish`);
export const unpublishCourse = (id: string) =>
  api.post<ApiSuccess<TeachCourse>>(`teach/courses/${id}/unpublish`);
export const archiveCourse = (id: string) =>
  api.post<ApiSuccess<TeachCourse>>(`teach/courses/${id}/archive`);

export const createAnnouncement = (id: string, input: AnnouncementInput) =>
  api.post<ApiSuccess<TeachAnnouncement>>(`teach/courses/${id}/announcements`, input);
